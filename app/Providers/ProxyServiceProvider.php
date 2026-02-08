<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ValidateApiKey;
use App\Services\ContentSanitizer;
use App\Services\ContentTypeDetector;
use App\Services\ImageCache;
use App\Services\UrlValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the remote content proxy.
 *
 * Registers core services as singletons and configures rate limiting.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ProxyServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     *
     * Binds ImageCache, ContentSanitizer, ContentTypeDetector as singletons,
     * and registers UrlValidator with its configuration dependencies.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ImageCache::class);
        $this->app->singleton(ContentTypeDetector::class);

        $this->app->singleton(ContentSanitizer::class, function () {
            return new ContentSanitizer(
                proxyBaseUrl: config('proxy.base_url'),
            );
        });

        $this->app->singleton(UrlValidator::class, function () {
            return new UrlValidator(
                blockedDomains: config('proxy.blocked_domains'),
                blockedIps: config('proxy.blocked_ips'),
                blockedRanges: config('proxy.blocked_ranges'),
                allowedSchemes: config('proxy.allowed_schemes'),
                allowedPorts: config('proxy.allowed_ports'),
            );
        });
    }

    /**
     * Bootstrap application services.
     *
     * Configures the 'proxy' rate limiter, keyed on the validated API key
     * when the request carries one and on the client IP otherwise. A key
     * may carry its own allowance, the window and the fallback allowance
     * come from the proxy configuration.
     *
     * @return void
     */
    public function boot(): void
    {
        RateLimiter::for('proxy', function (Request $request) {
            if (!config('proxy.rate_limit.enabled')) {
                return Limit::none();
            }

            // Only a key that exists in the database gets its own bucket:
            // anything else shares the caller's IP bucket, so a made-up
            // header cannot open a fresh allowance on every request.
            $apiKey = ValidateApiKey::resolve($request);
            $bucket = $apiKey !== null ? 'key:' . $apiKey->getKey() : 'ip:' . $request->ip();

            $maxRequests = $apiKey?->rate_limit ?? (int) config('proxy.rate_limit.max_requests');
            $windowSeconds = max(1, (int) config('proxy.rate_limit.window_seconds'));

            return new Limit(key: $bucket, maxAttempts: $maxRequests, decaySeconds: $windowSeconds);
        });
    }
}
