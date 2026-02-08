# API Reference

## Endpoints

### Health Check

```
GET /
```

Returns service name, version and status. No authentication.

**Response:**
```json
{
  "name": "Remote Content Proxy",
  "version": "1.0.0",
  "status": "ok"
}
```

```
GET /up
```

Laravel's liveness endpoint: `200` with an HTML page when the application boots, meant for uptime monitors.

### Proxy Content

```
GET /i/{base64Url}
```

Fetches and returns remote content (images, CSS, fonts, videos) through the proxy.

**Parameters:**

| Parameter | Location | Required | Description |
|-----------|----------|----------|-------------|
| `base64Url` | Path | Yes | URL-safe Base64 encoded content URL |
| `api_key` | Query/Header | If enabled | API key for authentication |

**Authentication Methods**, in the order they are looked up:

1. Header: `Authorization: Bearer your_key`
2. Header: `X-API-Key: your_key`
3. Query parameter: `?api_key=your_key`

Prefer a header when the client can send one: a query parameter ends up in web server access logs and browser history.

**Success Response:**

- Status: `200 OK`
- Body: Content binary data
- Headers:
  ```
  Content-Type: image/jpeg
  Content-Length: 12345
  Cache-Control: public, max-age=86400, immutable
  X-RateLimit-Limit: 100
  X-RateLimit-Remaining: 99
  ```
  plus the security headers listed in [Configuration](configuration.md#security-headers).

**Error Responses**, `text/plain` body with the message shown:

| Status | Body | When |
|--------|------|------|
| `400 Bad Request` | `Invalid Base64 encoded URL` | The path segment does not decode |
| `400 Bad Request` | `Invalid or blocked URL` | Scheme, host, port or resolved address refused |
| `401 Unauthorized` | `API key required` / `Invalid API key` | Missing, unknown or revoked key |
| `429 Too Many Requests` | Laravel's throttle response | Rate limit exceeded |
| `502 Bad Gateway` | `Failed to fetch content` | Remote error, redirect refused, unsupported type, too large, sanitization failed |
| `500 Internal Server Error` | `Error fetching content` | Unexpected exception |

The `400` and `502` bodies are deliberately generic; the reason is in the application log (`PROXY_LOG_LEVEL`).

## URL Encoding

The proxy uses URL-safe Base64 encoding:

1. Standard Base64 encode the URL
2. Replace `+` with `-`
3. Replace `/` with `_`
4. Remove padding `=`

**Example:**

```php
// PHP
function encodeUrl(string $url): string {
    $base64 = base64_encode($url);
    return rtrim(strtr($base64, '+/', '-_'), '=');
}

// Usage
$encoded = encodeUrl('https://example.com/image.jpg');
// Result: aHR0cHM6Ly9leGFtcGxlLmNvbS9pbWFnZS5qcGc
```

```javascript
// JavaScript
function encodeUrl(url) {
    return btoa(url)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}
```

**Full Request Example:**

```
https://your-proxy.com/i/aHR0cHM6Ly9leGFtcGxlLmNvbS9pbWFnZS5qcGc?api_key=your_key
```

## Rate Limiting

When rate limiting is enabled, responses include these headers:

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | Requests per window for this key (or for the IP) |
| `X-RateLimit-Remaining` | Remaining requests in the current window |
| `Retry-After` | Seconds to wait (on 429 only) |
| `X-RateLimit-Reset` | Unix timestamp when the window resets (on 429 only) |

The bucket is the API key when the request carries a valid one, the client IP otherwise. A key may have an allowance of its own (`apikey:limit`); others share `PROXY_RATE_LIMIT_MAX_REQUESTS`.
