<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to validate API key authentication on proxy requests.
 *
 * Extracts the API key from the Authorization Bearer header, X-API-Key
 * header, or api_key query parameter. Validates the key against the
 * database and updates the last-used timestamp on success.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ValidateApiKey
{
    /** Request attribute holding the resolved key, so it is looked up once */
    private const ATTRIBUTE = 'proxy.api_key';

    /**
     * Handle an incoming request by validating the API key.
     *
     * If API key requirement is disabled in config, the request passes through.
     * Otherwise, extracts and validates the key, returning 401 on failure.
     *
     * @param Request $request The incoming HTTP request
     * @param Closure $next    The next middleware handler
     * @return Response The HTTP response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('proxy.require_api_key')) {
            return $next($request);
        }

        if (self::extractApiKey($request) === null) {
            return response('API key required', 401);
        }

        $apiKey = self::resolve($request);

        if ($apiKey === null) {
            return response('Invalid API key', 401);
        }

        $apiKey->touchLastUsed();

        return $next($request);
    }

    /**
     * Look up the API key carried by the request.
     *
     * The rate limiter runs before this middleware and needs the same
     * answer, so the result is kept on the request and the database is
     * queried once whichever side asks first.
     *
     * @param Request $request The incoming HTTP request
     * @return ApiKey|null The active key, or null when absent, unknown or revoked
     */
    public static function resolve(Request $request): ?ApiKey
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $key = self::extractApiKey($request);
        $apiKey = $key === null ? null : ApiKey::findByKey($key);

        $request->attributes->set(self::ATTRIBUTE, $apiKey);

        return $apiKey;
    }

    /**
     * Extract the API key from the request.
     *
     * Checks in order: Authorization Bearer token, X-API-Key header,
     * then api_key query parameter.
     *
     * @param Request $request The incoming HTTP request
     * @return string|null The extracted API key, or null if not found
     */
    private static function extractApiKey(Request $request): ?string
    {
        // Check Authorization header: Bearer <key>
        $bearer = $request->bearerToken();
        if ($bearer !== null) {
            return $bearer;
        }

        // Check X-API-Key header
        $apiKeyHeader = $request->header('X-API-Key');
        if ($apiKeyHeader !== null) {
            return $apiKeyHeader;
        }

        // Check query parameter (a repeated parameter arrives as an array)
        $query = $request->query('api_key');

        return is_string($query) ? $query : null;
    }
}
