<?php

declare(strict_types=1);

use App\Services\ContentSanitizer;

beforeEach(function (): void {
    $this->proxyBaseUrl = 'https://proxy.example.com';
    $this->sanitizer = new ContentSanitizer();
    $this->sanitizerWithProxy = new ContentSanitizer(proxyBaseUrl: $this->proxyBaseUrl);
});

// --- Passthrough (binary content) ---

test('binary content passes through', function (): void {
    $binary = random_bytes(100);
    $result = $this->sanitizer->sanitize($binary, 'image/png');
    expect($result)->toBe($binary);
});

test('font content passes through', function (): void {
    $font = 'wOFF' . random_bytes(50);
    $result = $this->sanitizer->sanitize($font, 'font/woff');
    expect($result)->toBe($font);
});

test('video content passes through', function (): void {
    $video = random_bytes(200);
    $result = $this->sanitizer->sanitize($video, 'video/mp4');
    expect($result)->toBe($video);
});

// --- SVG sanitization ---

test('sanitizes valid svg', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="100" height="100"/></svg>';
    $result = $this->sanitizer->sanitize($svg, 'image/svg+xml');
    expect($result)->not->toBeNull();
    expect($result)->toContain('<svg');
    expect($result)->toContain('rect');
});

test('svg removes script tags', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><rect width="100" height="100"/></svg>';
    $result = $this->sanitizer->sanitize($svg, 'image/svg+xml');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('script');
});

test('rejects empty svg', function (): void {
    $result = $this->sanitizer->sanitize('', 'image/svg+xml');
    expect($result)->toBeNull();
});

// --- CSS basic sanitization ---

test('valid css passes', function (): void {
    $css = 'body { color: red; } .header { font-size: 14px; }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->toContain('color:red');
    expect($result)->toContain('font-size:14px');
});

test('css rejects javascript expression', function (): void {
    $css = 'body { width: expression(alert(1)); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->toBeNull();
});

test('css rejects javascript protocol quoted', function (): void {
    $css = 'body { background: url("javascript:alert(1)"); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->toBeNull();
});

test('css sanitizes javascript protocol unquoted', function (): void {
    $css = 'body { background: url(javascript:alert(1)); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('javascript');
});

test('css strips behavior property', function (): void {
    $css = 'body { behavior: url(malicious.htc); color: red; }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('behavior');
    expect($result)->toContain('color:red');
});

test('css strips moz binding property', function (): void {
    $css = 'body { -moz-binding: url(evil.xml#exploit); color: blue; }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('-moz-binding');
    expect($result)->toContain('color:blue');
});

test('css rejects data uri html', function (): void {
    $css = '@import url(data: text/html,<script>alert(1)</script>);';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->toBeNull();
});

test('css rejects script tags', function (): void {
    $css = '</style><script>alert(1)</script><style>';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->toBeNull();
});

test('css strips null bytes', function (): void {
    $css = "body { color: red; }\0";
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain("\0");
});

test('css rejects vbscript protocol', function (): void {
    $css = 'body { background: url(vbscript:exec); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->toBeNull();
});

// --- CSS @import removal ---

test('css removes import rules', function (): void {
    $css = '@import url("https://evil.com/tracker.css"); body { color: red; }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('@import');
    expect($result)->toContain('color:red');
});

test('css removes import with string', function (): void {
    $css = "@import 'https://evil.com/track.css'; body { color: red; }";
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('evil.com');
});

// --- CSS url() without proxy rewriting (about:invalid fallback) ---

test('css replaces external url with about invalid', function (): void {
    $css = 'body { background: url("https://evil.com/pixel.png"); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('evil.com');
    expect($result)->toContain('about:invalid');
});

test('css replaces protocol relative url without proxy', function (): void {
    $css = 'body { background: url("//evil.com/pixel.png"); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('evil.com');
    expect($result)->toContain('about:invalid');
});

test('css replaces absolute path url without proxy', function (): void {
    $css = 'body { background: url("/images/bg.png"); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('/images/bg.png');
    expect($result)->toContain('about:invalid');
});

test('css replaces relative path url without proxy', function (): void {
    $css = 'body { background: url("images/bg.png"); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('images/bg.png');
    expect($result)->toContain('about:invalid');
});

// --- CSS url() with proxy rewriting ---

test('css rewrites https url to proxy', function (): void {
    $originalUrl = 'https://cdn.example.com/bg.png';
    $css = "body { background: url(\"{$originalUrl}\"); }";
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('cdn.example.com');
    $expectedEncoded = base64url($originalUrl);
    expect($result)->toContain($this->proxyBaseUrl . '/i/' . $expectedEncoded);
});

test('css rewrites http url to proxy', function (): void {
    $originalUrl = 'http://images.example.com/photo.jpg';
    $css = "body { background: url(\"{$originalUrl}\"); }";
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    $expectedEncoded = base64url($originalUrl);
    expect($result)->toContain($this->proxyBaseUrl . '/i/' . $expectedEncoded);
});

test('css rewrites protocol relative url to proxy', function (): void {
    $css = 'body { background: url("//cdn.example.com/bg.png"); }';
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->not->toContain('cdn.example.com');
    // Protocol-relative URLs get https: prepended before encoding
    $expectedEncoded = base64url('https://cdn.example.com/bg.png');
    expect($result)->toContain($this->proxyBaseUrl . '/i/' . $expectedEncoded);
});

test('css rewrites url in font face', function (): void {
    $originalUrl = 'https://fonts.example.com/roboto.woff2';
    $css = "@font-face { src: url(\"{$originalUrl}\"); }";
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    $expectedEncoded = base64url($originalUrl);
    expect($result)->toContain($this->proxyBaseUrl . '/i/' . $expectedEncoded);
});

test('css rewrites url in media query', function (): void {
    $originalUrl = 'https://cdn.example.com/mobile-bg.png';
    $css = "@media screen { body { background: url(\"{$originalUrl}\"); color: red; } }";
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    $expectedEncoded = base64url($originalUrl);
    expect($result)->toContain($this->proxyBaseUrl . '/i/' . $expectedEncoded);
    expect($result)->toContain('color:red');
});

test('css rewrites multiple urls', function (): void {
    $url1 = 'https://a.com/1.png';
    $url2 = 'https://b.com/2.png';
    $css = "body { background: url(\"{$url1}\"), url(\"{$url2}\"); }";
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->toContain(base64url($url1));
    expect($result)->toContain(base64url($url2));
});

test('css does not rewrite non proxiable urls', function (): void {
    // Relative paths cannot be proxied (no scheme), should get about:invalid
    $css = 'body { background: url("/images/bg.png"); }';
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->toContain('about:invalid');
});

// --- CSS url() preservation (safe URLs remain unchanged) ---

test('css preserves data image uri', function (): void {
    $css = 'body { background: url("data:image/png;base64,iVBOR..."); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->toContain('data:image/png');
});

test('css preserves fragment reference', function (): void {
    $css = '.icon { filter: url(#drop-shadow); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->toContain('#drop-shadow');
});

test('css preserves data font uri', function (): void {
    $css = '@font-face { src: url("data:font/woff2;base64,d09G..."); }';
    $result = $this->sanitizer->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->toContain('data:font/woff2');
});

test('css preserves safe urls with proxy enabled', function (): void {
    // Safe URLs (data:, #fragment) should NOT be rewritten even with proxy enabled
    $css = 'body { background: url("data:image/png;base64,abc"); filter: url(#blur); }';
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    expect($result)->toContain('data:image/png');
    expect($result)->toContain('#blur');
    expect($result)->not->toContain($this->proxyBaseUrl);
});

// --- Mixed scenarios with proxy ---

test('css mixed safe and external urls with proxy', function (): void {
    $externalUrl = 'https://cdn.example.com/bg.png';
    $css = "body { background: url(\"{$externalUrl}\"), url(\"data:image/png;base64,abc\"); }";
    $result = $this->sanitizerWithProxy->sanitize($css, 'text/css');
    expect($result)->not->toBeNull();
    // External URL rewritten to proxy
    expect($result)->toContain(base64url($externalUrl));
    // Data URI preserved as-is
    expect($result)->toContain('data:image/png');
});
