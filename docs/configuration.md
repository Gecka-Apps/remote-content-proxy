# Configuration

All configuration is managed through environment variables and the `config/proxy.php` file.

## Environment Variables

Copy `.env.example` to `.env` and adjust the values:

```bash
cp .env.example .env
```

In production the configuration is cached (`php artisan optimize`, see [Deployment](deployment.md)): run it again after editing `.env`.

### Proxy Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `PROXY_REQUIRE_API_KEY` | `true` | Require API key authentication |
| `PROXY_BASE_URL` | `APP_URL` | Public proxy URL for rewriting CSS `url()` references |
| `PROXY_MAX_FILE_SIZE` | `10485760` | Maximum file size for images/CSS/fonts (10 MB) |
| `PROXY_MAX_VIDEO_SIZE` | `52428800` | Maximum file size for videos (50 MB) |
| `PROXY_TIMEOUT` | `10` | Request timeout in seconds |
| `PROXY_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds |
| `PROXY_LOW_SPEED_LIMIT` | `1000` | Abort if speed drops below (bytes/sec) |
| `PROXY_LOW_SPEED_TIME` | `10` | Time threshold for low speed detection |
| `PROXY_MAX_RETRIES` | `3` | Attempts per resource (1 s, then 2 s between them), on connection failures and 5xx answers only |
| `PROXY_USER_AGENT` | Thunderbird UA | User agent for fetching content |

### Rate Limiting

| Variable | Default | Description |
|----------|---------|-------------|
| `PROXY_RATE_LIMIT_ENABLED` | `true` | Enable rate limiting |
| `PROXY_RATE_LIMIT_MAX_REQUESTS` | `100` | Max requests per window for a key without an allowance of its own (`apikey:limit`), and for requests without a valid key (counted per IP) |
| `PROXY_RATE_LIMIT_WINDOW` | `60` | Time window in seconds |

### Caching

| Variable | Default | Description |
|----------|---------|-------------|
| `PROXY_CACHE_ENABLED` | `true` | Enable content caching |
| `PROXY_CACHE_DRIVER` | `file` | Cache driver (`file` or `redis`, see below) |
| `PROXY_CACHE_TTL` | `86400` | Cache TTL in seconds (24 hours) |
| `PROXY_CACHE_PATH` | `storage/app/proxy-cache` | Cache storage path |
| `PROXY_CACHE_MAX_SIZE` | `104857600` | Maximum cache size (100 MB) |
| `PROXY_CACHE_CLEANUP_THRESHOLD` | `90` | Cleanup at % of max size |
| `PROXY_CACHE_RESPECT_CLIENT_HEADERS` | `false` | Honor client cache bypass (Ctrl+F5) |

**Redis driver:** the connection comes from `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` and `REDIS_CLIENT` (`phpredis` by default, which needs the `redis` extension; `predis` needs `composer require predis/predis`). The `file` driver keeps everything under `PROXY_CACHE_PATH` and needs nothing else.

**Client Cache Control:**

When `PROXY_CACHE_RESPECT_CLIENT_HEADERS` is `true`, the proxy respects HTTP cache control headers from clients:
- `Cache-Control: no-cache` - Bypasses cache and fetches fresh content
- `Cache-Control: no-store` - Bypasses cache and fetches fresh content
- `Pragma: no-cache` - Bypasses cache (HTTP/1.0 compatibility)

This allows users to force refresh content with Ctrl+F5 in their browser. Set to `false` in production to prevent cache bypass abuse.

### Logging

| Variable | Default | Description |
|----------|---------|-------------|
| `PROXY_LOG_LEVEL` | `normal` | Log verbosity: `minimal`, `normal`, or `verbose` |
| `PROXY_LOG_FULL_URL` | `true` | Log full URL on errors (set to `false` if URLs contain sensitive data) |

**Log Levels:**

| Level | What is logged |
|-------|----------------|
| `minimal` | Errors only (blocked URLs, fetch failures, exceptions) |
| `normal` | Errors + warnings (retries, redirects, content issues) |
| `verbose` | Everything including cache hits and successful fetches |

**Log Format Examples:**

```
# Error (always logged): type, reason, URL hash, the URL itself when PROXY_LOG_FULL_URL is on, and the details of the check that failed
[2026-01-14 20:39:54] production.ERROR: [Proxy] BLOCKED_URL: IP is in private range (RFC1918), 192.168.0.0/16 {"url_hash":"16e218b70a465bb9","url":"https://192.168.1.1/image.png","host":"192.168.1.1","error_type":"BLOCKED_URL","reason":"IP is in private range (RFC1918), 192.168.0.0/16","ip":"192.168.1.1"}

# Warning (normal + verbose)
[2026-01-14 20:39:54] production.WARNING: [Proxy] Retrying fetch (attempt 2/3) {"url_hash":"16e218b70a465bb9","url":"https://example.com/image.png","host":"example.com","delay_ms":1000,"reason":"HTTP 503"}

# Info (verbose only): hash and host, never the full URL
[2026-01-14 20:39:54] production.INFO: [Proxy] Fetched and cached {"url_hash":"16e218b70a465bb9","host":"example.com","content_type":"image/png","size":12345}
```

## Settings in `config/proxy.php` only

A few lists have no environment variable and are edited in `config/proxy.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `allowed_ports` | `80, 443, 8080, 8443` | Destination ports accepted; a URL with any other explicit port is refused |
| `allowed_schemes` | `http, https` | URL schemes accepted |
| `allowed_content_types` | see below | MIME types served |
| `blocked_domains`, `blocked_ips`, `blocked_ranges` | see below | Destinations refused |
| `security_headers` | see below | Headers added to every response |

## Blocked Domains & IPs

The proxy blocks access to internal networks and cloud metadata endpoints by default:

**Blocked Domains:**
- `localhost`, `localhost.localdomain`
- `internal`, `local`, `private`, `intra`, `lan`, `corp`, `home`
- `metadata.google.internal`, `metadata.goog`
- `kubernetes.default.svc`, `kubernetes.default`

**Blocked IPs:**
- `127.0.0.1`, `0.0.0.0`, `::1`, `::`
- `169.254.169.254` (AWS metadata)
- `169.254.169.253` (Azure metadata)
- `169.254.169.252` (GCP metadata)

**Blocked Ranges** (`config/proxy.php`, `blocked_ranges`):
- Every range that is not globally routable: RFC1918, carrier-grade NAT (`100.64.0.0/10`), loopback, link-local, documentation, benchmarking, multicast, `240.0.0.0/4`
- IPv6 unique local, link-local, site-local, multicast, documentation, discard
- The IPv6 transition prefixes carrying an IPv4 address: IPv4-mapped, IPv4-compatible, NAT64, 6to4, Teredo

## Security Headers

All responses include these security headers:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; media-src 'self'; font-src 'self'
Referrer-Policy: no-referrer
```

## Allowed Content Types

### Images
- `image/jpeg`
- `image/png`
- `image/gif`
- `image/webp`
- `image/svg+xml` (sanitized)
- `image/bmp`
- `image/x-icon`
- `image/vnd.microsoft.icon`
- `image/avif`

### Stylesheets
- `text/css` (sanitized)

### Fonts
- `font/woff`
- `font/woff2`
- `font/ttf`
- `font/otf`
- `font/eot`
- `application/font-woff`
- `application/font-woff2`
- `application/x-font-ttf`
- `application/x-font-otf`
- `application/vnd.ms-fontobject`

### Videos
- `video/mp4`
- `video/webm`
- `video/ogg`
- `video/quicktime`
- `video/x-msvideo` (AVI)
- `video/x-matroska` (MKV)
