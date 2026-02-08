# Security

## Overview

Remote Content Proxy is a security-focused proxy designed for email clients (Thunderbird, Roundcube). It intercepts external resource requests from HTML emails and serves them through a controlled pipeline that validates, sanitizes, and caches content before delivering it to the client.

All external content (images, CSS, fonts, videos) loaded by the email client passes through this proxy, preventing direct contact between the email client and potentially malicious remote servers.

## Protection Layers

### 1. SSRF Prevention

Server-Side Request Forgery is the primary threat model for any server-side proxy.

**Domain Blocklist**
- Blocks `localhost`, `*.local`, `*.internal`, and other internal hostnames
- Blocks cloud metadata endpoints: `metadata.google.internal`, `kubernetes.default.svc`
- Subdomain matching: blocking `evil.com` also blocks `sub.evil.com`
- Configurable via `config('proxy.blocked_domains')`

**IP Validation**
- Blocks every range that is not globally routable, from an explicit CIDR list: RFC1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), carrier-grade NAT (100.64.0.0/10), loopback, link-local, `0.0.0.0/8`, the documentation and benchmarking ranges, multicast (224.0.0.0/4) and `240.0.0.0/4`
- Same for IPv6: `::1`, `::`, unique local (fc00::/7), link-local (fe80::/10), site-local, multicast (ff00::/8), the documentation and discard ranges
- Blocks the IPv6 transition prefixes that embed an IPv4 address: IPv4-mapped (`::ffff:0:0/96`), IPv4-compatible (`::/96`), NAT64 (`64:ff9b::/96`), 6to4 (`2002::/16`), Teredo (`2001::/32`)
- Blocks cloud metadata IPs: `169.254.169.254` (AWS), `169.254.169.253` (Azure)
- Explicit blocklist for `127.0.0.1`, `0.0.0.0`, `::1`, `::` and others
- Configurable via `config('proxy.blocked_ips')` and `config('proxy.blocked_ranges')`

**IPv6-Mapped IPv4 Detection**
- Detects `::ffff:127.0.0.1` (dotted-decimal mapped)
- Detects `::ffff:7f00:1` (hex-mapped) and converts to IPv4 for validation
- Detects deprecated `::127.0.0.1` (IPv4-compatible) format
- Prevents SSRF bypass via IPv6 encoding of private IPv4 addresses

**DNS Rebinding Protection**
- DNS is resolved **once** before the HTTP request
- The resolved IP is pinned via `CURLOPT_RESOLVE` for the entire request lifecycle
- Prevents time-of-check/time-of-use attacks where DNS changes between validation and fetch

**Redirect Validation**
- Redirects are never followed by the HTTP client (`allow_redirects` is off); the proxy follows them itself, one hop at a time, up to 5 hops
- Each `Location:` target (relative ones resolved against the current URL) is resolved and validated against the same SSRF rules as the original URL
- The next hop is pinned via `CURLOPT_RESOLVE` to the IP that was just validated, so the DNS pinning survives every redirect
- Only HTTP/HTTPS redirect protocols allowed (`CURLOPT_REDIR_PROTOCOLS`)

### 2. URL Validation

**Scheme Allowlist**
- Only `http` and `https` schemes are accepted
- `ftp`, `file`, `data`, `javascript`, `vbscript`, `php` schemes are blocked
- Configurable via `config('proxy.allowed_schemes')`

**Port Restrictions**
- Only ports **80**, **443**, **8080**, and **8443** are allowed
- Prevents the proxy from being used as a port scanner against internal services
- Blocked port attempts are **logged** (`Log::warning`) with hostname and port number for monitoring
- Configurable via `config('proxy.allowed_ports')`
- Requests with no explicit port use the scheme default (80/443) and are always allowed

**URL Normalization**
- Strips whitespace, null bytes (`\0`), and control characters (`\x00-\x1F`, `\x7F`)
- Does NOT urldecode the URL: percent-encoded bytes reach the remote server as they are
- The whole URL must pass `filter_var(FILTER_VALIDATE_URL)`, which rejects the confusable forms (a backslash before `@`, a second `:` in the authority) where a lenient parser and cURL could disagree on the host

**Path and query are not inspected.** Once the scheme, the host and the port are settled, nothing in the rest of the URL changes what the proxy connects to, and cURL reads the authority the same way `parse_url()` does (userinfo tricks such as `https://good.com%5c@evil.com/` land on `evil.com` for both). A query value that happens to contain `data:` or `../` is the remote server's business and is passed through.

### 3. Content Security

**Content-Type Validation**
- Allowlist of MIME types: images, CSS, fonts, videos (see `config('proxy.allowed_content_types')`)
- Server-provided Content-Type is parsed and validated
- If missing or untrusted, magic byte detection is used as fallback

**Magic Byte Detection** (`ContentTypeDetector`)
- Verifies actual file content against known binary signatures (JPEG, PNG, GIF, WebP, AVIF, BMP, ICO, WOFF/WOFF2, TTF, OTF, EOT, MP4, QuickTime, WebM, Matroska, Ogg, AVI)
- SVG detection uses strict regex: must start with `<?xml`, `<!DOCTYPE`, or `<svg` within the first 1024 bytes
- Prevents content-type spoofing attacks

**Size Limits**
- Default: 10 MB for images/CSS/fonts, 50 MB for videos
- Configurable via `PROXY_MAX_FILE_SIZE` and `PROXY_MAX_VIDEO_SIZE`
- Enforced while downloading, not after: the body goes into a sink capped at the larger of the two limits, and cURL aborts the transfer as soon as the cap is crossed. A server that streams without end, announces a wrong `Content-Length` or serves a compression bomb (the cap counts decompressed bytes) cannot fill the worker's memory. A `Content-Length` above the cap is refused before the body starts
- The per-type limit is applied to the downloaded body afterwards

**SVG Sanitization** (`enshrined/svg-sanitize`)
- Removes `<script>` tags and all JavaScript event handlers
- Removes external references (remote resources, XLink)
- Blocks XML entity injection (XXE)
- Minifies output

**CSS Sanitization** (`sabberworm/php-css-parser`)

CSS is parsed into an Abstract Syntax Tree (AST) and each node is inspected:

| Threat | Action |
|---|---|
| `@import` rules | **Removed** — prevents loading external stylesheets |
| `expression()` function | **Rejected** — IE JavaScript execution in CSS |
| `javascript:` / `vbscript:` in `url()` | **Rejected** — script protocol injection |
| `behavior` property | **Stripped** — IE HTC component loading |
| `-moz-binding` property | **Stripped** — Firefox XBL binding |
| External `url()` (http/https) | **Rewritten** to proxy URL (or `about:invalid` if no base URL configured) |
| Protocol-relative `url()` (`//...`) | **Rewritten** to proxy URL with `https:` prefix |
| Relative/absolute path `url()` | **Replaced** with `about:invalid` (cannot be resolved) |
| `data:image/*`, `data:font/*` URIs | **Preserved** — safe embedded content |
| `#fragment` references | **Preserved** — internal SVG/filter references |
| HTML/script injection in CSS | **Rejected** — `<script>`, `</script>`, `<!--`, `-->` |
| Null bytes | **Stripped** before parsing |

**CSS URL Rewriting**: When `PROXY_BASE_URL` is configured, external `url()` references are rewritten to pass through the proxy instead of being removed:
```css
/* Before */
body { background: url("https://cdn.example.com/bg.png"); }
/* After */
body { background: url("https://your-proxy.com/i/{base64url}"); }
```

This allows CSS sub-resources (background images, fonts) to remain functional while still being proxied through the security pipeline.

### 4. Network Security

**SSL/TLS**
- Certificate validation is enforced (cURL defaults)
- Only HTTP and HTTPS protocols allowed at the cURL level (`CURLOPT_PROTOCOLS`)

**Timeout Controls**
- Connection timeout: 10s (configurable via `PROXY_CONNECT_TIMEOUT`)
- Transfer timeout: 10s (configurable via `PROXY_TIMEOUT`)
- Low-speed detection: transfer aborted if speed drops below 1000 bytes/sec for 10s

**Retry with Exponential Backoff**
- Up to 3 attempts (configurable) with exponential delays (1s, 2s)
- Only connection failures and 5xx answers are retried; a 4xx or a redirect is handled at once, so a dead link does not hold a worker through the backoff schedule
- Each retry is logged for monitoring

### 5. Access Control

**API Key Authentication**
- 256-bit cryptographically random keys (64 hex characters)
- Keys are **hashed with SHA-256** before storage — the plaintext key is shown **once** at creation and cannot be retrieved afterwards
- Database compromise does not reveal usable API keys
- Lookup: `hash('sha256', $input)` is compared against stored hash
- Supports revocation with timestamp
- Multiple authentication methods: `Authorization: Bearer`, `X-API-Key` header, `?api_key=` query parameter
- Configurable via `PROXY_REQUIRE_API_KEY`

**Rate Limiting**
- Per API key when the request carries a valid one, per client IP otherwise; an unknown or revoked key does not open a bucket of its own, the attempts count against the IP
- Each key may carry its own allowance (`apikey:create --rate-limit`, `apikey:limit`); a key without one uses `PROXY_RATE_LIMIT_MAX_REQUESTS`. The window is the same for every key
- Default: 100 requests per 60 seconds
- Configurable via `PROXY_RATE_LIMIT_*`

**Usage Tracking**
- `last_used_at` timestamp updated on each valid request
- Throttled to max once per 5 minutes to avoid excessive DB writes
- Enables detection of unused/abandoned keys

### 6. Response Hardening

All responses (success and error) include security headers:

| Header | Value | Purpose |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME type sniffing |
| `X-Frame-Options` | `DENY` | Prevents framing/clickjacking |
| `Content-Security-Policy` | `default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; media-src 'self'; font-src 'self'` | Restricts resource loading |
| `Referrer-Policy` | `no-referrer` | Prevents referer header leakage |

Successful responses also include:
- `Cache-Control: public, max-age=86400, immutable` for client-side caching

### 7. Cache Security

- Cache keys are derived from URL hashes (SHA-256)
- Cache bypass via client headers (`Cache-Control: no-cache`) is **disabled by default** to prevent abuse
- Configurable via `PROXY_CACHE_RESPECT_CLIENT_HEADERS`
- Cache stampede protection with lock mechanism
- Automatic cleanup of expired entries

## Logging

Security events are logged with structured context:

| Event | Level | Details |
|---|---|---|
| Blocked URL | ERROR | URL hash, host, block reason |
| Blocked port | WARNING | Port number, hostname |
| Invalid URL | ERROR | URL hash, validation details |
| Fetch failure | ERROR | URL hash, HTTP status, attempts |
| Auth failure | Middleware | Via Laravel's 401 response |
| Rate limit | Middleware | Via Laravel's rate limiter |
| CSS injection | WARNING | Injection type detected |
| Redirect to blocked URL | ERROR | Original URL, block reason |
| Too many redirects | ERROR | Original URL, hop limit |

URL hashing in logs prevents sensitive data leakage. Full URL logging can be enabled for debugging via `PROXY_LOG_FULL_URL=true`.

## Limitations

- **Port restrictions**: Only ports 80, 443, 8080, 8443 are allowed. Resources served on non-standard ports (e.g. `:3000`, `:8888`) will be blocked. Adjust `proxy.allowed_ports` if needed.
- **@import in CSS**: All `@import` rules are stripped. CSS that relies on `@import` for loading other stylesheets will lose those imports.
- **Relative URLs in CSS**: Relative and absolute-path URLs (`/images/bg.png`, `images/bg.png`) cannot be resolved without knowing the original base URL. They are replaced with `about:invalid`.
- **SVG remote references**: All remote references in SVGs are removed by the sanitizer.
- **No WebSocket support**: The proxy only handles HTTP/HTTPS requests.
- **API key in CSS sub-resources**: When CSS URLs are rewritten to proxy URLs, the client (Thunderbird extension) is responsible for adding authentication to those sub-requests.
- **API key in the query string**: `?api_key=` is accepted for clients that cannot set headers, but it ends up in the web server access log and in browser history. Prefer `Authorization: Bearer` or `X-API-Key` whenever the client can send a header.

## Best Practices

1. **Always enable API key authentication** in production (`PROXY_REQUIRE_API_KEY=true`)
2. **Set `PROXY_BASE_URL`** to your proxy's public URL for CSS URL rewriting to work
3. **Use HTTPS** for the proxy itself
4. **Monitor logs** for recurring blocked ports — this may indicate legitimate resources on non-standard ports
5. **Keep dependencies updated** (`enshrined/svg-sanitize`, `sabberworm/php-css-parser`)
6. **Disable full URL logging** in production if URLs may contain sensitive data (`PROXY_LOG_FULL_URL=false`)
7. **Configure rate limits** based on expected usage patterns, with a larger allowance for a key shared by many users (a webmail) than for a personal one
8. **Review blocked domains** and add organization-specific entries
9. **Disable client cache bypass** in production (`PROXY_CACHE_RESPECT_CLIENT_HEADERS=false`)
