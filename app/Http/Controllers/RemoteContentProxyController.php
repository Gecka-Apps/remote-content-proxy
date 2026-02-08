<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Psr\Http\Message\ResponseInterface;
use App\Support\BoundedStream;
use App\Services\ImageCache;
use App\Services\UrlValidator;
use App\Services\ContentSanitizer;
use App\Services\ContentTypeDetector;

/**
 * Controller for proxying remote content (images, fonts, CSS, videos).
 *
 * Receives Base64-encoded URLs, validates them against security rules,
 * fetches content from the remote server, validating every redirect hop,
 * sanitizes the response, and serves it with appropriate
 * caching and security headers.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class RemoteContentProxyController extends Controller
{
    /** @var array<int, string> */
    private array $allowedContentTypes;
    private int $maxFileSize;
    private int $maxVideoSize;
    private int $timeout;
    private int $connectTimeout;
    private string $userAgent;
    /** @var array<string, string> */
    private array $securityHeaders;
    private int $maxRetries;
    private int $lowSpeedLimit;
    private int $lowSpeedTime;
    private string $logLevel;
    private bool $logFullUrlOnError;
    private bool $respectClientCacheHeaders;

    private const LOG_MINIMAL = 'minimal';
    private const LOG_NORMAL = 'normal';
    private const LOG_VERBOSE = 'verbose';

    /** Maximum number of redirects followed for one resource */
    private const MAX_REDIRECTS = 5;

    /**
     * Create a new proxy controller instance.
     *
     * @param ImageCache          $cache               The image cache service
     * @param UrlValidator        $urlValidator         The URL validation service
     * @param ContentSanitizer    $contentSanitizer     The content sanitization service
     * @param ContentTypeDetector $contentTypeDetector  The content type detection service
     */
    public function __construct(
        private ImageCache $cache,
        private UrlValidator $urlValidator,
        private ContentSanitizer $contentSanitizer,
        private ContentTypeDetector $contentTypeDetector,
    ) {
        $this->allowedContentTypes = config('proxy.allowed_content_types');
        $this->maxFileSize = config('proxy.max_file_size');
        $this->maxVideoSize = config('proxy.max_video_size');
        $this->timeout = config('proxy.timeout');
        $this->connectTimeout = config('proxy.connect_timeout');
        $this->userAgent = config('proxy.user_agent');
        $this->securityHeaders = config('proxy.security_headers');
        $this->maxRetries = config('proxy.max_retries');
        $this->lowSpeedLimit = config('proxy.low_speed_limit');
        $this->lowSpeedTime = config('proxy.low_speed_time');
        $this->logLevel = config('proxy.logging.level');
        $this->logFullUrlOnError = config('proxy.logging.log_full_url_on_error');
        $this->respectClientCacheHeaders = config('proxy.cache.respect_client_cache_headers');
    }

    /**
     * Handle proxy request via Base64 path.
     *
     * URL format: /{base64EncodedUrl}
     * Uses URL-safe Base64: + -> -, / -> _, no padding.
     *
     * @param Request $request   The incoming HTTP request
     * @param string  $base64Url The URL-safe Base64-encoded target URL
     * @return Response The proxied content or an error response
     */
    public function proxy(Request $request, string $base64Url): Response
    {
        $url = $this->decodeBase64Url($base64Url);

        if ($url === null) {
            $this->logError('INVALID_BASE64', 'Failed to decode Base64 URL', null, [
                'encoded_url' => substr($base64Url, 0, 50) . (strlen($base64Url) > 50 ? '...' : ''),
            ]);
            return $this->errorResponse('Invalid Base64 encoded URL', 400);
        }

        // Resolve IP once to prevent DNS rebinding attacks
        $hostValidation = $this->urlValidator->resolveAndValidateHost($url);
        if (!$hostValidation['valid']) {
            $this->logError('BLOCKED_URL', $hostValidation['reason'], $url, $hostValidation['details']);
            return $this->errorResponse('Invalid or blocked URL', 400);
        }

        $resolvedIp = $hostValidation['ip'];

        $urlValidation = $this->urlValidator->validateUrl($url, $resolvedIp);
        if (!$urlValidation['valid']) {
            $this->logError('INVALID_URL', $urlValidation['reason'], $url, $urlValidation['details']);
            return $this->errorResponse('Invalid or blocked URL', 400);
        }

        try {
            // Check if client requests cache bypass (Ctrl+F5)
            $bypassCache = $this->shouldBypassCache($request);

            // Try to get from cache first (unless bypassed)
            $cachedData = null;
            if (!$bypassCache) {
                $cachedData = $this->cache->get($url);
            }

            if ($cachedData !== null) {
                $this->logVerbose('Cache hit', $url);
                return $this->contentResponse($cachedData['content'], $cachedData['content_type']);
            }

            if ($bypassCache) {
                $this->logVerbose('Cache bypassed by client request', $url);
            }

            // Cache miss - acquire lock to prevent stampede
            $lock = $this->cache->acquireLock($url, 5.0);

            if ($lock === null) {
                // Could not acquire lock (timeout or error)
                // Check cache again in case another request filled it while we waited
                $cachedData = $this->cache->get($url);
                if ($cachedData !== null) {
                    $this->logVerbose('Cache hit after lock timeout', $url);
                    return $this->contentResponse($cachedData['content'], $cachedData['content_type']);
                }

                // Still no cache, proceed without lock (fallback)
                $this->logWarning('Failed to acquire cache lock, proceeding without lock', $url);
            }

            try {
                // Double-check cache after acquiring lock
                if ($lock !== null) {
                    $cachedData = $this->cache->get($url);
                    if ($cachedData !== null) {
                        $this->logVerbose('Cache hit after lock acquired', $url);
                        return $this->contentResponse($cachedData['content'], $cachedData['content_type']);
                    }
                }

                // Fetch from remote source with retry
                $data = $this->fetchWithRetry($url, $resolvedIp);

                if ($data === null) {
                    // Error already logged in fetchWithRetry
                    return $this->errorResponse('Failed to fetch content', 502);
                }

                // Cache for future requests
                $this->cache->put($url, $data['content'], $data['content_type']);

                $this->logVerbose('Fetched and cached', $url, [
                    'content_type' => $data['content_type'],
                    'size' => strlen($data['content']),
                ]);

                return $this->contentResponse($data['content'], $data['content_type']);
            } finally {
                // Always release lock
                if ($lock !== null) {
                    $this->cache->releaseLock($lock);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('EXCEPTION', $e->getMessage(), $url, [
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->errorResponse('Error fetching content', 500);
        }
    }

    /**
     * Fetch content, following redirects one hop at a time.
     *
     * Redirects are not delegated to the HTTP client: each Location target
     * goes through the same host resolution and URL validation as the
     * original request, and the next hop is pinned to the IP that was just
     * validated. A redirect therefore cannot reach an address the validator
     * has not seen, whatever the DNS answers in between.
     *
     * @param string $url        The URL to fetch
     * @param string $resolvedIp The pre-resolved IP address to pin cURL to
     * @return array{content: string, content_type: string}|null Fetched data, or null on failure
     */
    private function fetchWithRetry(string $url, string $resolvedIp): ?array
    {
        $currentUrl = $url;
        $currentIp = $resolvedIp;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->sendRequest($currentUrl, $currentIp);

            if ($response === null) {
                return null;
            }

            if (!$response->redirect()) {
                if (!$response->successful()) {
                    $this->logError('FETCH_FAILED', "HTTP {$response->status()}", $url);
                    return null;
                }

                return $this->processContent($url, $response->body(), $response->header('Content-Type'));
            }

            if ($hop === self::MAX_REDIRECTS) {
                $this->logError('FETCH_FAILED', 'Too many redirects', $url, ['max' => self::MAX_REDIRECTS]);
                return null;
            }

            $location = $response->header('Location');
            if ($location === '') {
                $this->logError('FETCH_FAILED', "HTTP {$response->status()} without Location header", $url);
                return null;
            }

            try {
                $target = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
            } catch (\InvalidArgumentException $e) {
                $this->logError('FETCH_FAILED', "Malformed redirect target: {$e->getMessage()}", $url);
                return null;
            }

            $hostValidation = $this->urlValidator->resolveAndValidateHost($target);
            if (!$hostValidation['valid']) {
                $this->logError('FETCH_FAILED', "Redirect to blocked URL: {$hostValidation['reason']}", $url, $hostValidation['details']);
                return null;
            }

            $urlValidation = $this->urlValidator->validateUrl($target, $hostValidation['ip']);
            if (!$urlValidation['valid']) {
                $this->logError('FETCH_FAILED', "Redirect to invalid URL: {$urlValidation['reason']}", $url, $urlValidation['details']);
                return null;
            }

            $this->logVerbose('Following redirect', $url, [
                'status' => $response->status(),
                'hop' => $hop + 1,
            ]);

            $currentUrl = $target;
            $currentIp = $hostValidation['ip'];
        }

        return null;
    }

    /**
     * Send a single request to a validated URL, pinned to its validated IP.
     *
     * Retries with exponential backoff apply to connection failures and
     * 5xx answers only. A 4xx or a redirect is returned as is, so a dead
     * link costs one round trip instead of holding the worker through the
     * whole backoff schedule.
     *
     * The body is downloaded into a BoundedStream capped at the largest
     * configured size, so a server that streams without end, lies about
     * its Content-Length or serves a compression bomb is cut off at the
     * cap instead of filling the worker's memory. The per-type limit is
     * applied afterwards by processContent().
     *
     * @param string $url        The URL to request
     * @param string $resolvedIp The IP address the host must resolve to
     * @return ClientResponse|null The response, or null when the transfer failed
     */
    private function sendRequest(string $url, string $resolvedIp): ?ClientResponse
    {
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);

        // CURLOPT_RESOLVE entries take IPv6 addresses in brackets
        $address = str_contains($resolvedIp, ':') ? "[{$resolvedIp}]" : $resolvedIp;

        $cap = max($this->maxFileSize, $this->maxVideoSize);

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $sink = new BoundedStream($cap);
            $failure = null;

            try {
                $response = Http::withOptions([
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"],
                        CURLOPT_LOW_SPEED_LIMIT => $this->lowSpeedLimit,
                        CURLOPT_LOW_SPEED_TIME => $this->lowSpeedTime,
                        ...$this->getProtocolCurlOptions(),
                        CURLOPT_ENCODING => '',
                    ],
                    'allow_redirects' => false,
                    'sink' => $sink,
                    // Give up before the body starts when its announced size is already too big
                    'on_headers' => function (ResponseInterface $headers) use ($sink) {
                        $length = $headers->getHeaderLine('Content-Length');
                        if ($length !== '' && (int) $length > $sink->limit()) {
                            $sink->reject();
                            throw new \RuntimeException("Announced Content-Length {$length} exceeds {$sink->limit()} bytes");
                        }
                    },
                ])
                ->withHeaders([
                    'Accept' => 'image/*, text/css, font/*, application/font-*, application/vnd.ms-fontobject, video/*',
                ])
                ->withUserAgent($this->userAgent)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->get($url);

                if (!$response->serverError()) {
                    return $response;
                }

                $failure = "HTTP {$response->status()}";
            } catch (ConnectionException $e) {
                if ($sink->exceeded()) {
                    $this->logError('FETCH_FAILED', "File too large: exceeds {$cap} bytes", $url);
                    return null;
                }

                $failure = "Connection failed: {$e->getMessage()}";
            } catch (\Throwable $e) {
                $this->logError('FETCH_FAILED', $e->getMessage(), $url, [
                    'exception_class' => get_class($e),
                ]);
                return null;
            }

            if ($attempt === $this->maxRetries) {
                $this->logError('FETCH_FAILED', $failure, $url, ['attempts' => $attempt]);
                return null;
            }

            $delay = (int) pow(2, $attempt - 1) * 1000;
            $this->logWarning("Retrying fetch (attempt " . ($attempt + 1) . "/{$this->maxRetries})", $url, [
                'delay_ms' => $delay,
                'reason' => $failure,
            ]);
            Sleep::for($delay)->milliseconds();
        }

        return null;
    }

    /**
     * Validate and sanitize fetched content.
     *
     * Detects or verifies the content type, enforces size limits (with a
     * separate limit for video), and passes the content through the
     * sanitization pipeline.
     *
     * @param string      $url         The original request URL (for logging)
     * @param string      $content     The fetched binary content
     * @param string|null $contentType The Content-Type header from the response
     * @return array{content: string, content_type: string}|null Processed data, or null on rejection
     */
    private function processContent(string $url, string $content, ?string $contentType): ?array
    {
        // Parse content type first to determine size limit
        $parsedContentType = $this->contentTypeDetector->parseContentType($contentType);

        if (empty($parsedContentType)) {
            // If no content type provided, try to detect from content
            $detectedType = $this->contentTypeDetector->detectContentType($content);
            if ($detectedType === null) {
                $this->logError('FETCH_FAILED', 'Unable to determine content type', $url);
                return null;
            }
            $parsedContentType = $detectedType;
        }

        if (!in_array($parsedContentType, $this->allowedContentTypes, true)) {
            // Try to detect actual type
            $detectedType = $this->contentTypeDetector->detectContentType($content);

            if ($detectedType === null || !in_array($detectedType, $this->allowedContentTypes, true)) {
                $this->logError('FETCH_FAILED', "Unsupported content type: {$parsedContentType}", $url);
                return null;
            }
            $parsedContentType = $detectedType;
        }

        // Check file size with appropriate limit based on content type
        $contentSize = strlen($content);
        $isVideo = str_starts_with($parsedContentType, 'video/');
        $maxSize = $isVideo ? $this->maxVideoSize : $this->maxFileSize;

        if ($contentSize > $maxSize) {
            $this->logError('FETCH_FAILED', "File too large: {$contentSize} bytes (max: {$maxSize})", $url);
            return null;
        }

        // Sanitize content based on type
        $sanitizedContent = $this->contentSanitizer->sanitize($content, $parsedContentType);
        if ($sanitizedContent === null) {
            $this->logError('FETCH_FAILED', "Content sanitization failed for type: {$parsedContentType}", $url);
            return null;
        }

        return [
            'content' => $sanitizedContent,
            'content_type' => $parsedContentType,
        ];
    }

    /**
     * Decode a URL-safe Base64 string back to the original URL.
     *
     * Reverses the encoding: - -> +, _ -> /, and restores padding.
     *
     * @param string $base64 The URL-safe Base64-encoded string
     * @return string|null The decoded URL, or null on invalid input
     */
    private function decodeBase64Url(string $base64): ?string
    {
        $base64 = str_replace(['-', '_'], ['+', '/'], $base64);

        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }

    /**
     * Build a successful content response with caching and security headers.
     *
     * @param string $content     The content body
     * @param string $contentType The MIME type for the Content-Type header
     * @return Response The HTTP response
     */
    private function contentResponse(string $content, string $contentType): Response
    {
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => strlen($content),
            'Cache-Control' => 'public, max-age=86400, immutable',
        ];

        // Add security headers from config
        foreach ($this->securityHeaders as $header => $value) {
            $headers[$header] = $value;
        }

        return new Response($content, 200, $headers);
    }

    /**
     * Build a plain-text error response with security headers.
     *
     * @param string $message The error message body
     * @param int    $status  The HTTP status code
     * @return Response The HTTP error response
     */
    private function errorResponse(string $message, int $status): Response
    {
        $headers = [
            'Content-Type' => 'text/plain',
        ];

        // Add security headers to error responses too
        foreach ($this->securityHeaders as $header => $value) {
            $headers[$header] = $value;
        }

        return new Response($message, $status, $headers);
    }

    /**
     * Check if client requests cache bypass via HTTP headers.
     *
     * Looks for Cache-Control: no-cache or Pragma: no-cache (Ctrl+F5).
     *
     * @param Request $request The incoming HTTP request
     * @return bool True if the cache should be bypassed
     */
    private function shouldBypassCache(Request $request): bool
    {
        if (!$this->respectClientCacheHeaders) {
            return false;
        }

        // Check Cache-Control header
        $cacheControl = $request->header('Cache-Control');
        if ($cacheControl !== null
            && (str_contains(strtolower($cacheControl), 'no-cache')
             || str_contains(strtolower($cacheControl), 'no-store'))) {
            return true;
        }

        // Check Pragma header (HTTP/1.0 compatibility)
        $pragma = $request->header('Pragma');
        if ($pragma !== null && str_contains(strtolower($pragma), 'no-cache')) {
            return true;
        }

        return false;
    }

    /**
     * Get cURL options to restrict allowed protocols.
     *
     * Uses CURLOPT_PROTOCOLS_STR (PHP 8.3+) when available,
     * falls back to CURLOPT_PROTOCOLS for older versions.
     *
     * @return array<int, mixed> The cURL protocol options
     */
    private function getProtocolCurlOptions(): array
    {
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            return [
                CURLOPT_PROTOCOLS_STR => 'http,https',
                CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            ];
        }

        return [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
    }

    /**
     * Hash a URL for safe logging (prevents sensitive data leakage).
     *
     * @param string $url The URL to hash
     * @return string The first 16 characters of the SHA-256 hash
     */
    private function hashUrl(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }

    /**
     * Build a log context array with URL information and extra details.
     *
     * Includes URL hash, optionally the full URL (if configured), and
     * the extracted host for log filtering.
     *
     * @param string|null          $url   The request URL (null if unavailable)
     * @param array<string, mixed> $extra Additional context key-value pairs
     * @return array<string, mixed> The assembled log context
     */
    private function buildLogContext(?string $url, array $extra = []): array
    {
        $context = [];

        if ($url !== null) {
            $context['url_hash'] = $this->hashUrl($url);

            if ($this->logFullUrlOnError) {
                $context['url'] = $url;
            }

            // Extract host for easier filtering
            $parsed = parse_url($url);
            if (isset($parsed['host'])) {
                $context['host'] = $parsed['host'];
            }
        }

        return [...$context, ...$extra];
    }

    /**
     * Log an error (always logged regardless of configured level).
     *
     * @param string               $errorType The error category identifier
     * @param string               $reason    Human-readable error description
     * @param string|null          $url       The related URL (null if unavailable)
     * @param array<string, mixed> $details   Additional context details
     * @return void
     */
    private function logError(string $errorType, string $reason, ?string $url, array $details = []): void
    {
        $context = $this->buildLogContext($url, [
            'error_type' => $errorType,
            'reason' => $reason,
            ...$details,
        ]);

        Log::error("[Proxy] {$errorType}: {$reason}", $context);
    }

    /**
     * Log a warning (logged at 'normal' and 'verbose' levels).
     *
     * @param string               $message The warning message
     * @param string|null          $url     The related URL (null if unavailable)
     * @param array<string, mixed> $details Additional context details
     * @return void
     */
    private function logWarning(string $message, ?string $url, array $details = []): void
    {
        if ($this->logLevel === self::LOG_MINIMAL) {
            return;
        }

        $context = $this->buildLogContext($url, $details);
        Log::warning("[Proxy] {$message}", $context);
    }

    /**
     * Log verbose info (only logged at 'verbose' level).
     *
     * @param string               $message The info message
     * @param string|null          $url     The related URL (null if unavailable)
     * @param array<string, mixed> $details Additional context details
     * @return void
     */
    private function logVerbose(string $message, ?string $url, array $details = []): void
    {
        if ($this->logLevel !== self::LOG_VERBOSE) {
            return;
        }

        $context = [];
        if ($url !== null) {
            $context['url_hash'] = $this->hashUrl($url);
            // Don't log full URL for success messages (verbose), only hash
            $parsed = parse_url($url);
            if (isset($parsed['host'])) {
                $context['host'] = $parsed['host'];
            }
        }

        Log::info("[Proxy] {$message}", [...$context, ...$details]);
    }
}
