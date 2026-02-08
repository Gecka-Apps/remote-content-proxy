<?php

declare(strict_types=1);

use App\Services\UrlValidator;

beforeEach(function (): void {
    $this->validator = new UrlValidator(
        blockedDomains: ['evil.com', 'localhost', 'metadata.google.internal'],
        blockedIps: ['127.0.0.1', '0.0.0.0', '::1', '169.254.169.254'],
        allowedSchemes: ['http', 'https'],
    );
});

test('valid https url', function (): void {
    $result = $this->validator->validateUrl('https://example.com/image.png', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

test('valid http url', function (): void {
    $result = $this->validator->validateUrl('http://example.com/style.css', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

test('rejects ftp scheme', function (): void {
    $result = $this->validator->validateUrl('ftp://example.com/file', '93.184.216.34');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('Scheme not allowed');
});

test('rejects javascript scheme', function (): void {
    $result = $this->validator->validateUrl('javascript:alert(1)', '127.0.0.1');
    expect($result['valid'])->toBeFalse();
});

test('accepts query values that look like schemes', function (): void {
    $result = $this->validator->validateUrl('https://example.com/img?format:pdf&u=data:x&r=ftp://mirror', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

test('accepts encoded bytes in path', function (): void {
    $result = $this->validator->validateUrl('https://example.com/a%00b/%2e%2e/c%0d%0a.png', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

test('strips raw control characters', function (): void {
    $result = $this->validator->validateUrl("https://example.com/ima\r\nge.png", '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

// --- Host parsing agrees with curl on confusable URLs ---

test('userinfo does not hide the real host', function (): void {
    foreach ([
        'https://good.com@evil.com/',
        'https://good.com:443@evil.com/',
        'https://good.com%5c@evil.com/',
        'https://good.com%2f@evil.com/',
        'https://good.com%23@evil.com/',
    ] as $url) {
        $result = $this->validator->resolveAndValidateHost($url);
        expect($result['valid'])->toBeFalse("{$url} must resolve to evil.com and be blocked");
        expect($result['details']['host'])->toBe('evil.com');
    }
});

test('backslash before userinfo is rejected', function (): void {
    $result = $this->validator->validateUrl('https://good.com\\@evil.com/', '93.184.216.34');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('Invalid URL format');
});

test('rejects empty host', function (): void {
    $result = $this->validator->validateUrl('https:///path', '93.184.216.34');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks localhost', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://localhost/image.png');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('blacklisted');
});

test('resolve blocks private ip', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://192.168.1.1/image.png');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('private range');
});

test('resolve blocks loopback ip', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://127.0.0.1/image.png');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks aws metadata ip', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://169.254.169.254/latest/meta-data/');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks cloud metadata domain', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://metadata.google.internal/computeMetadata/');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks subdomain of blocked', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://sub.evil.com/image.png');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks missing host', function (): void {
    $result = $this->validator->resolveAndValidateHost('not-a-url');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('Missing');
});

test('resolve valid public ip', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://8.8.8.8/dns-query');
    expect($result['valid'])->toBeTrue();
    expect($result['ip'])->toBe('8.8.8.8');
});

test('resolve blocks ipv6 loopback', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://[::1]/image.png');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks 10 range', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://10.0.0.1/image.png');
    expect($result['valid'])->toBeFalse();
});

test('url normalizes null bytes', function (): void {
    // A raw null byte is stripped by normalizeUrl, the URL is then valid
    $result = $this->validator->validateUrl("https://example.com/image\0.png", '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

// --- IPv6-mapped IPv4 SSRF bypass ---

test('resolve blocks ipv6 mapped loopback', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://[::ffff:127.0.0.1]/image.png');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('IPv6-mapped');
});

test('resolve blocks ipv6 mapped private ip', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://[::ffff:192.168.1.1]/image.png');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('IPv6-mapped');
});

test('resolve blocks ipv6 mapped metadata', function (): void {
    $result = $this->validator->resolveAndValidateHost('https://[::ffff:169.254.169.254]/meta-data/');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks ipv6 mapped hex loopback', function (): void {
    // ::ffff:7f00:1 = 127.0.0.1 in hex
    $result = $this->validator->resolveAndValidateHost('https://[::ffff:7f00:1]/image.png');
    expect($result['valid'])->toBeFalse();
});

test('resolve blocks ipv4 compatible ipv6', function (): void {
    // ::127.0.0.1 (deprecated but still exists)
    $result = $this->validator->resolveAndValidateHost('https://[::127.0.0.1]/image.png');
    expect($result['valid'])->toBeFalse();
});

// --- Non-routable ranges ---

test('resolve blocks non routable ranges', function (string $ip, string $label): void {
    $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
    $result = $this->validator->resolveAndValidateHost("https://{$host}/image.png");

    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain($label);
})->with([
    'CGNAT' => ['100.64.12.1', 'shared address space'],
    'IETF protocol assignments' => ['192.0.0.9', 'IETF protocol assignments'],
    'benchmarking' => ['198.18.0.1', 'benchmarking'],
    'documentation TEST-NET-3' => ['203.0.113.5', 'documentation'],
    'multicast' => ['224.0.0.251', 'multicast'],
    'reserved' => ['240.0.0.1', 'reserved'],
    'broadcast' => ['255.255.255.255', 'reserved'],
    'unique local IPv6' => ['fd12:3456::1', 'unique local'],
    'link-local IPv6' => ['fe80::1', 'link-local'],
    'NAT64' => ['64:ff9b::7f00:1', 'NAT64'],
    '6to4' => ['2002:7f00:1::1', '6to4'],
    'Teredo' => ['2001:0:7f00:1::1', 'Teredo'],
    'IPv6 multicast' => ['ff02::1', 'multicast'],
]);

test('resolve allows public addresses', function (): void {
    foreach (['93.184.216.34', '100.128.0.1', '2606:2800:220:1:248:1893:25c8:1946'] as $ip) {
        $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $result = $this->validator->resolveAndValidateHost("https://{$host}/image.png");

        expect($result['valid'])->toBeTrue("{$ip} should be allowed");
        expect($result['ip'])->toBe($ip);
    }
});

// --- Port validation ---

test('rejects non standard port', function (): void {
    $result = $this->validator->validateUrl('https://example.com:22/image.png', '93.184.216.34');
    expect($result['valid'])->toBeFalse();
    expect($result['reason'])->toContain('port');
});

test('rejects high port', function (): void {
    $result = $this->validator->validateUrl('https://example.com:9999/image.png', '93.184.216.34');
    expect($result['valid'])->toBeFalse();
});

test('allows port 80', function (): void {
    $result = $this->validator->validateUrl('http://example.com:80/image.png', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

test('allows port 443', function (): void {
    $result = $this->validator->validateUrl('https://example.com:443/image.png', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

test('allows port 8080', function (): void {
    $result = $this->validator->validateUrl('http://example.com:8080/image.png', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});

test('allows no port', function (): void {
    $result = $this->validator->validateUrl('https://example.com/image.png', '93.184.216.34');
    expect($result['valid'])->toBeTrue();
});
