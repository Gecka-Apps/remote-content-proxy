<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Services\ImageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('proxy.require_api_key', true);
    Config::set('proxy.cache.enabled', false);
    Config::set('proxy.rate_limit.enabled', false);
    Config::set('proxy.logging.level', 'minimal');

    $apiKey = ApiKey::generate('Test');
    $this->validApiKey = $apiKey->getPlainKey();
});

// --- Health Check ---

test('health check returns json', function (): void {
    $response = $this->getJson('/');

    $response->assertOk();
    $response->assertJson([
        'name' => 'Remote Content Proxy',
        'status' => 'ok',
    ]);
});

// --- Authentication ---

test('proxy requires api key', function (): void {
    $encoded = base64url('https://example.com/image.png');

    $response = $this->get('/i/' . $encoded);

    $response->assertStatus(401);
});

test('proxy rejects invalid api key', function (): void {
    $encoded = base64url('https://example.com/image.png');

    $response = $this->get('/i/' . $encoded . '?api_key=invalid');

    $response->assertStatus(401);
});

test('proxy accepts valid api key in query', function (): void {
    Http::fake([
        'example.com/*' => Http::response(
            "\xFF\xD8\xFF\xE0" . random_bytes(50),
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $encoded = base64url('https://example.com/image.jpg');

    $response = $this->get('/i/' . $encoded . '?api_key=' . $this->validApiKey);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg');
});

test('proxy accepts valid bearer token', function (): void {
    Http::fake([
        'example.com/*' => Http::response(
            "\x89PNG\r\n\x1A\n" . random_bytes(50),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $encoded = base64url('https://example.com/image.png');

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertOk();
});

test('proxy accepts x api key header', function (): void {
    Http::fake([
        'example.com/*' => Http::response(
            "\x89PNG\r\n\x1A\n" . random_bytes(50),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $encoded = base64url('https://example.com/image.png');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertOk();
});

// --- API key not required ---

test('proxy works without key when not required', function (): void {
    Config::set('proxy.require_api_key', false);

    Http::fake([
        'example.com/*' => Http::response(
            "\xFF\xD8\xFF\xE0" . random_bytes(50),
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $encoded = base64url('https://example.com/image.jpg');

    $response = $this->get('/i/' . $encoded);

    $response->assertOk();
});

// --- Invalid Base64 ---

test('proxy rejects invalid base64', function (): void {
    // The route constraint only allows [A-Za-z0-9_-]+
    // An empty string or invalid chars will get a 404 from the router
    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/!!!invalid!!!');

    $response->assertStatus(404);
});

// --- Blocked URLs ---

test('proxy blocks localhost', function (): void {
    $encoded = base64url('http://localhost/secret');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(400);
});

test('proxy blocks private ip', function (): void {
    $encoded = base64url('http://192.168.1.1/image.png');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(400);
});

test('proxy blocks metadata endpoint', function (): void {
    $encoded = base64url('http://169.254.169.254/latest/meta-data/');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(400);
});

// --- Content Type Validation ---

test('proxy rejects unsupported content type', function (): void {
    Http::fake([
        'example.com/*' => Http::response(
            '<html><body>Hello</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $encoded = base64url('https://example.com/page.html');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    // Should be rejected (unsupported content type)
    expect([400, 502])->toContain($response->getStatusCode());
});

// --- Security Headers ---

test('proxy includes security headers', function (): void {
    Http::fake([
        'example.com/*' => Http::response(
            "\xFF\xD8\xFF\xE0" . random_bytes(50),
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $encoded = base64url('https://example.com/image.jpg');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
});

// --- Remote server errors ---

test('proxy returns 502 on remote failure', function (): void {
    Http::fake([
        'example.com/*' => Http::response('Server Error', 500),
    ]);

    $encoded = base64url('https://example.com/image.jpg');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(502);
});

// --- CSS proxying ---

test('proxy serves css content', function (): void {
    Http::fake([
        'example.com/*' => Http::response(
            'body { color: red; }',
            200,
            ['Content-Type' => 'text/css'],
        ),
    ]);

    $encoded = base64url('https://example.com/style.css');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/css');
});

// --- File size limit ---

test('proxy rejects oversized file', function (): void {
    Config::set('proxy.max_file_size', 100);

    Http::fake([
        'example.com/*' => Http::response(
            "\xFF\xD8\xFF\xE0" . str_repeat('x', 200),
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $encoded = base64url('https://example.com/big.jpg');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(502);
});

// --- Error handling ---

test('proxy catches throwable errors', function (): void {
    // Mock ImageCache to throw a TypeError (extends \Error, not \Exception)
    $this->mock(ImageCache::class, function ($mock) {
        $mock->shouldReceive('get')->andThrow(new \TypeError('Simulated type error'));
        $mock->shouldReceive('acquireLock')->never();
        $mock->shouldReceive('releaseLock')->never();
    });

    $encoded = base64url('https://example.com/image.jpg');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    // Should return 500 instead of crashing with an unhandled TypeError
    $response->assertStatus(500);
    expect($response->getContent())->toContain('Error fetching content');
});

// --- Retries ---

test('proxy does not retry client errors', function (): void {
    Sleep::fake();
    Config::set('proxy.max_retries', 3);

    Http::fake([
        'example.com/*' => Http::response('Not Found', 404),
    ]);

    $encoded = base64url('https://example.com/gone.jpg');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(502);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('proxy retries server errors with backoff', function (): void {
    Sleep::fake();
    Config::set('proxy.max_retries', 3);

    Http::fake([
        'example.com/*' => Http::response('Server Error', 503),
    ]);

    $encoded = base64url('https://example.com/flaky.jpg');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(502);
    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(1000)->milliseconds(),
        Sleep::for(2000)->milliseconds(),
    ]);
});

// --- Redirects ---

test('proxy follows redirect to allowed host', function (): void {
    Http::fake([
        'example.com/*' => Http::response('', 302, ['Location' => 'https://example.org/final.png']),
        'example.org/*' => Http::response(
            "\x89PNG\r\n\x1A\n" . random_bytes(50),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $encoded = base64url('https://example.com/moved.png');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/png');
    Http::assertSentCount(2);
});

test('proxy resolves relative redirect against current url', function (): void {
    Http::fake([
        'example.com/images/moved.png' => Http::response('', 301, ['Location' => '../static/final.png']),
        'example.com/static/final.png' => Http::response(
            "\x89PNG\r\n\x1A\n" . random_bytes(50),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $encoded = base64url('https://example.com/images/moved.png');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertOk();
    Http::assertSent(fn($request) => $request->url() === 'https://example.com/static/final.png');
});

test('proxy refuses redirect to blocked address', function (): void {
    Http::fake([
        'example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/latest/meta-data']),
        '127.0.0.1/*' => Http::response('secret', 200, ['Content-Type' => 'image/png']),
    ]);

    $encoded = base64url('https://example.com/moved.png');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(502);
    Http::assertSentCount(1);
    Http::assertNotSent(fn($request) => str_contains($request->url(), '127.0.0.1'));
});

test('proxy stops after too many redirects', function (): void {
    Http::fake([
        'example.com/*' => Http::response('', 302, ['Location' => 'https://example.com/loop.png']),
    ]);

    $encoded = base64url('https://example.com/loop.png');

    $response = $this->withHeader('X-API-Key', $this->validApiKey)
        ->get('/i/' . $encoded);

    $response->assertStatus(502);
    Http::assertSentCount(6);
});

// --- Rate limiting ---

test('rate limit ignores unknown api keys and counts per ip', function (): void {
    Config::set('proxy.rate_limit.enabled', true);
    Config::set('proxy.rate_limit.max_requests', 2);

    $encoded = base64url('https://example.com/image.png');

    foreach (['bogus-1', 'bogus-2'] as $key) {
        $this->withHeader('X-API-Key', $key)
            ->get('/i/' . $encoded)
            ->assertStatus(401);
    }

    $this->withHeader('X-API-Key', 'bogus-3')
        ->get('/i/' . $encoded)
        ->assertStatus(429);
});

test('rate limit gives each valid api key its own bucket', function (): void {
    Config::set('proxy.rate_limit.enabled', true);
    Config::set('proxy.rate_limit.max_requests', 1);

    Http::fake([
        'example.com/*' => Http::response(
            "\xFF\xD8\xFF\xE0" . random_bytes(50),
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $otherKey = ApiKey::generate('Other')->getPlainKey();
    $encoded = base64url('https://example.com/image.jpg');

    $this->withHeader('X-API-Key', $this->validApiKey)->get('/i/' . $encoded)->assertOk();
    $this->withHeader('X-API-Key', $this->validApiKey)->get('/i/' . $encoded)->assertStatus(429);
    $this->withHeader('X-API-Key', $otherKey)->get('/i/' . $encoded)->assertOk();
});

test('rate limit uses the allowance of the key', function (): void {
    Config::set('proxy.rate_limit.enabled', true);
    Config::set('proxy.rate_limit.max_requests', 1);

    Http::fake([
        'example.com/*' => Http::response(
            "\xFF\xD8\xFF\xE0" . random_bytes(50),
            200,
            ['Content-Type' => 'image/jpeg'],
        ),
    ]);

    $generousKey = ApiKey::generate('Webmail', rateLimit: 3)->getPlainKey();
    $encoded = base64url('https://example.com/image.jpg');

    for ($i = 0; $i < 3; $i++) {
        $this->withHeader('X-API-Key', $generousKey)->get('/i/' . $encoded)->assertOk();
    }
    $this->withHeader('X-API-Key', $generousKey)->get('/i/' . $encoded)->assertStatus(429);

    $this->withHeader('X-API-Key', $this->validApiKey)->get('/i/' . $encoded)->assertOk();
    $this->withHeader('X-API-Key', $this->validApiKey)->get('/i/' . $encoded)->assertStatus(429);
});
