<?php

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

return [
    /*
    |--------------------------------------------------------------------------
    | API Key Authentication
    |--------------------------------------------------------------------------
    |
    | Set to true to require API key authentication for all proxy requests.
    | Set to false to allow open access without authentication.
    |
    */
    'require_api_key' => env('PROXY_REQUIRE_API_KEY', true),

    /*
    |--------------------------------------------------------------------------
    | Proxy Base URL
    |--------------------------------------------------------------------------
    |
    | Public base URL of this proxy instance. Used to rewrite url() references
    | inside proxied CSS files so that sub-resources (images, fonts) are also
    | fetched through the proxy instead of being stripped.
    |
    | When set, CSS url("https://example.com/bg.png") becomes:
    |   url("{base_url}/i/{base64url(https://example.com/bg.png)}")
    |
    | When null, external URLs are replaced with "about:invalid".
    | Defaults to APP_URL.
    |
    */
    'base_url' => env('PROXY_BASE_URL', env('APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | Allowed Content Types
    |--------------------------------------------------------------------------
    |
    | List of allowed MIME types that can be proxied.
    | Organized by category: images, stylesheets, fonts, and videos.
    |
    */
    'allowed_content_types' => [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/bmp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/avif',

        // Stylesheets
        'text/css',

        // Fonts
        'font/woff',
        'font/woff2',
        'font/ttf',
        'font/otf',
        'font/eot',
        'application/font-woff',
        'application/font-woff2',
        'application/x-font-ttf',
        'application/x-font-otf',
        'application/vnd.ms-fontobject',

        // Videos
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed size for proxied files in bytes.
    | Images/CSS/Fonts default: 10 MB (10 * 1024 * 1024)
    | Videos default: 50 MB (50 * 1024 * 1024)
    |
    */
    'max_file_size' => env('PROXY_MAX_FILE_SIZE', 10 * 1024 * 1024),
    'max_video_size' => env('PROXY_MAX_VIDEO_SIZE', 50 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for fetching remote images.
    | Default: 10 seconds
    |
    */
    'timeout' => env('PROXY_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Connect Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for initial connection (includes SSL handshake).
    | Some servers (like IBM) may need longer connection times.
    | Default: 10 seconds
    |
    */
    'connect_timeout' => env('PROXY_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Low Speed Detection
    |--------------------------------------------------------------------------
    |
    | Detect stalled transfers. If transfer speed drops below low_speed_limit
    | bytes per second for low_speed_time seconds, the transfer is aborted.
    |
    */
    'low_speed_limit' => env('PROXY_LOW_SPEED_LIMIT', 1000), // bytes per second
    'low_speed_time' => env('PROXY_LOW_SPEED_TIME', 10), // seconds

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Number of retry attempts for failed image fetches with exponential backoff.
    |
    */
    'max_retries' => env('PROXY_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    |
    | User agent string to use when fetching remote images.
    | Some servers check the user agent and may block requests with default agents.
    | Default: Thunderbird user agent for better compatibility with email services.
    |
    */
    'user_agent' => env('PROXY_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Thunderbird/146.0.1'),

    /*
    |--------------------------------------------------------------------------
    | Blocked Domains
    |--------------------------------------------------------------------------
    |
    | List of domains that should be blocked from being proxied.
    | This helps prevent SSRF attacks and access to internal services.
    |
    */
    'blocked_domains' => [
        // Localhost variations
        'localhost',
        'localhost.localdomain',

        // Internal/private network names
        'internal',
        'local',
        'private',
        'intra',
        'lan',
        'corp',
        'home',

        // Cloud metadata endpoints (SSRF targets)
        'metadata.google.internal',
        'metadata.goog',
        'kubernetes.default.svc',
        'kubernetes.default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked IPs
    |--------------------------------------------------------------------------
    |
    | Individual IP addresses that must never be contacted, checked before
    | the ranges below.
    |
    */
    'blocked_ips' => [
        // Localhost
        '127.0.0.1',
        '0.0.0.0',
        '::1',
        '::',

        // AWS metadata
        '169.254.169.254',

        // Azure metadata
        '169.254.169.253',

        // Google Cloud metadata
        '169.254.169.252',

        // Link-local (already covered by the ranges below but explicit)
        '169.254.0.1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked Ranges
    |--------------------------------------------------------------------------
    |
    | CIDR ranges a resolved address must not fall into, with the label used
    | in the log line. Covers everything that is not globally routable:
    | private, loopback, link-local, carrier-grade NAT, documentation,
    | multicast, and the IPv6 transition prefixes that embed an IPv4 address
    | (IPv4-mapped, NAT64, 6to4, Teredo).
    |
    */
    'blocked_ranges' => [
        // IPv4
        '0.0.0.0/8' => 'this-network range',
        '10.0.0.0/8' => 'private range (RFC1918)',
        '100.64.0.0/10' => 'shared address space (CGNAT, RFC6598)',
        '127.0.0.0/8' => 'loopback range',
        '169.254.0.0/16' => 'link-local range',
        '172.16.0.0/12' => 'private range (RFC1918)',
        '192.0.0.0/24' => 'IETF protocol assignments range',
        '192.0.2.0/24' => 'documentation range (TEST-NET-1)',
        '192.88.99.0/24' => '6to4 relay anycast range',
        '192.168.0.0/16' => 'private range (RFC1918)',
        '198.18.0.0/15' => 'benchmarking range',
        '198.51.100.0/24' => 'documentation range (TEST-NET-2)',
        '203.0.113.0/24' => 'documentation range (TEST-NET-3)',
        '224.0.0.0/4' => 'multicast range',
        '240.0.0.0/4' => 'reserved range',

        // IPv6
        '::/128' => 'unspecified address',
        '::1/128' => 'loopback address',
        '::/96' => 'IPv4-compatible range',
        '::ffff:0:0/96' => 'IPv4-mapped range',
        '64:ff9b::/96' => 'NAT64 range',
        '64:ff9b:1::/48' => 'local-use NAT64 range',
        '100::/64' => 'discard-only range',
        '2001::/32' => 'Teredo range',
        '2001:db8::/32' => 'documentation range',
        '2002::/16' => '6to4 range',
        'fc00::/7' => 'unique local range',
        'fe80::/10' => 'link-local range',
        'fec0::/10' => 'site-local range',
        'ff00::/8' => 'multicast range',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed URL Schemes
    |--------------------------------------------------------------------------
    |
    | List of allowed URL schemes for image URLs.
    | Only http and https should be allowed for security.
    |
    */
    'allowed_schemes' => ['http', 'https'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Ports
    |--------------------------------------------------------------------------
    |
    | Restrict which destination ports can be contacted. Only URLs with these
    | ports (or no explicit port) are allowed. This prevents the proxy from
    | being used as a port scanner against internal services.
    |
    | Default: 80 (HTTP), 443 (HTTPS), 8080 and 8443 (common alternatives).
    | Requests to any other port are rejected and logged.
    |
    */
    'allowed_ports' => [80, 443, 8080, 8443],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Security headers to add to all responses (images and errors).
    |
    */
    'security_headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; media-src 'self'; font-src 'self'",
        'Referrer-Policy' => 'no-referrer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting to prevent abuse.
    | Limits are applied per API key, or per IP when the request carries no
    | valid key. max_requests is the allowance of a key that has none of its
    | own (see apikey:create --rate-limit and apikey:limit); the window is
    | the same for every key.
    |
    */
    'rate_limit' => [
        'enabled' => env('PROXY_RATE_LIMIT_ENABLED', true),
        'max_requests' => env('PROXY_RATE_LIMIT_MAX_REQUESTS', 100), // requests per window
        'window_seconds' => env('PROXY_RATE_LIMIT_WINDOW', 60), // time window in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging verbosity and behavior.
    |
    | Levels:
    | - 'minimal': Only log errors (blocked URLs, fetch failures, exceptions)
    | - 'normal': Log errors and warnings (retries, redirects, content issues)
    | - 'verbose': Log everything including successful fetches and cache hits
    |
    | When log_full_url is true, the full URL is logged on errors for debugging.
    | When false, only a hash is logged (safer for production with sensitive URLs).
    |
    */
    'logging' => [
        'level' => env('PROXY_LOG_LEVEL', 'normal'),
        'log_full_url_on_error' => env('PROXY_LOG_FULL_URL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Cache
    |--------------------------------------------------------------------------
    |
    | Configuration for image caching to improve performance and reduce load.
    |
    | respect_client_cache_headers: When true, honors client cache control headers
    | (Cache-Control: no-cache, Pragma: no-cache) to bypass cache on Ctrl+F5.
    | Set to false in production to prevent cache bypass abuse.
    |
    */
    'cache' => [
        'enabled' => env('PROXY_CACHE_ENABLED', true),
        'driver' => env('PROXY_CACHE_DRIVER', 'file'), // 'file' or 'redis'
        'ttl' => env('PROXY_CACHE_TTL', 86400), // 24 hours in seconds
        'path' => env('PROXY_CACHE_PATH', storage_path('app/proxy-cache')),
        'max_size' => env('PROXY_CACHE_MAX_SIZE', 100 * 1024 * 1024), // 100 MB
        'cleanup_threshold' => env('PROXY_CACHE_CLEANUP_THRESHOLD', 90), // Start cleanup at 90% capacity
        'respect_client_cache_headers' => env('PROXY_CACHE_RESPECT_CLIENT_HEADERS', false),
    ],
];
