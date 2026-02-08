<?php

declare(strict_types=1);

use App\Services\ImageCache;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $this->cachePath = sys_get_temp_dir() . '/proxy-cache-test-' . uniqid();

    Config::set('proxy.cache.enabled', true);
    Config::set('proxy.cache.driver', 'file');
    Config::set('proxy.cache.ttl', 3600);
    Config::set('proxy.cache.path', $this->cachePath);
    Config::set('proxy.cache.max_size', 10 * 1024 * 1024);
    Config::set('proxy.cache.cleanup_threshold', 90);
});

afterEach(function (): void {
    removeDirectory($this->cachePath);
});

// --- Constructor / Configuration ---

test('constructor creates cache directory', function (): void {
    expect($this->cachePath)->not->toBeDirectory();

    new ImageCache();

    expect($this->cachePath)->toBeDirectory();
});

test('get driver returns configured driver', function (): void {
    $cache = new ImageCache();

    expect($cache->getDriver())->toBe('file');
});

test('get cache path returns configured path', function (): void {
    $cache = new ImageCache();

    expect($cache->getCachePath())->toBe($this->cachePath);
});

test('get ttl returns configured ttl', function (): void {
    $cache = new ImageCache();

    expect($cache->getTtl())->toBe(3600);
});

test('get max size returns configured max size', function (): void {
    $cache = new ImageCache();

    expect($cache->getMaxSize())->toBe(10 * 1024 * 1024);
});

// --- Get / Put ---

test('get returns null when cache disabled', function (): void {
    Config::set('proxy.cache.enabled', false);
    $cache = new ImageCache();

    expect($cache->get('https://example.com/image.png'))->toBeNull();
});

test('get returns null on cache miss', function (): void {
    $cache = new ImageCache();

    expect($cache->get('https://example.com/nonexistent.png'))->toBeNull();
});

test('put and get roundtrip', function (): void {
    $cache = new ImageCache();
    $url = 'https://example.com/image.png';
    $content = random_bytes(100);
    $contentType = 'image/png';

    $cache->put($url, $content, $contentType);
    $result = $cache->get($url);

    expect($result)->not->toBeNull();
    expect($result['content'])->toBe($content);
    expect($result['content_type'])->toBe($contentType);
});

test('put does nothing when cache disabled', function (): void {
    Config::set('proxy.cache.enabled', false);
    $cache = new ImageCache();

    $cache->put('https://example.com/image.png', 'content', 'image/png');

    // Re-enable to check nothing was stored
    Config::set('proxy.cache.enabled', true);
    $cache2 = new ImageCache();
    expect($cache2->get('https://example.com/image.png'))->toBeNull();
});

test('get returns null for expired entry', function (): void {
    Config::set('proxy.cache.ttl', 1);
    $cache = new ImageCache();
    $url = 'https://example.com/image.png';

    $cache->put($url, 'content', 'image/png');

    // Manually set expiration to the past
    $cacheKey = 'proxy_' . hash('sha256', $url);
    $metaFile = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey . '.meta';
    $meta = json_decode(file_get_contents($metaFile), true);
    $meta['expires_at'] = time() - 100;
    file_put_contents($metaFile, json_encode($meta));

    expect($cache->get($url))->toBeNull();
});

test('different urls have different cache entries', function (): void {
    $cache = new ImageCache();

    $cache->put('https://example.com/a.png', 'content-a', 'image/png');
    $cache->put('https://example.com/b.png', 'content-b', 'image/jpeg');

    $resultA = $cache->get('https://example.com/a.png');
    $resultB = $cache->get('https://example.com/b.png');

    expect($resultA['content'])->toBe('content-a');
    expect($resultA['content_type'])->toBe('image/png');
    expect($resultB['content'])->toBe('content-b');
    expect($resultB['content_type'])->toBe('image/jpeg');
});

// --- Clear ---

test('clear removes all cached entries', function (): void {
    $cache = new ImageCache();

    $cache->put('https://example.com/a.png', 'content-a', 'image/png');
    $cache->put('https://example.com/b.png', 'content-b', 'image/jpeg');

    $cache->clear();

    expect($cache->get('https://example.com/a.png'))->toBeNull();
    expect($cache->get('https://example.com/b.png'))->toBeNull();
});

// --- Cleanup ---

test('cleanup removes expired entries', function (): void {
    $cache = new ImageCache();
    $url = 'https://example.com/old.png';

    $cache->put($url, 'old-content', 'image/png');

    // Manually expire the entry
    $cacheKey = 'proxy_' . hash('sha256', $url);
    $metaFile = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey . '.meta';
    $meta = json_decode(file_get_contents($metaFile), true);
    $meta['expires_at'] = time() - 100;
    file_put_contents($metaFile, json_encode($meta));

    $result = $cache->cleanup(expiredOnly: true);

    expect($result['removed'])->toBeGreaterThanOrEqual(1);
    expect($result['freed'])->toBeGreaterThan(0);
});

test('cleanup keeps valid entries', function (): void {
    $cache = new ImageCache();
    $url = 'https://example.com/fresh.png';

    $cache->put($url, 'fresh-content', 'image/png');

    $result = $cache->cleanup(expiredOnly: true);

    expect($result['removed'])->toBe(0);
    expect($cache->get($url))->not->toBeNull();
});

test('cleanup returns zero for non file driver', function (): void {
    Config::set('proxy.cache.driver', 'redis');
    $cache = new ImageCache();

    $result = $cache->cleanup();

    expect($result['removed'])->toBe(0);
    expect($result['freed'])->toBe(0);
});

// --- Stats ---

test('get stats with empty cache', function (): void {
    $cache = new ImageCache();

    $stats = $cache->getStats();

    expect($stats['count'])->toBe(0);
    expect($stats['size'])->toBe(0);
    expect($stats['oldest'])->toBeNull();
    expect($stats['newest'])->toBeNull();
});

test('get stats with entries', function (): void {
    $cache = new ImageCache();
    $content = str_repeat('x', 1000);

    $cache->put('https://example.com/a.png', $content, 'image/png');
    $cache->put('https://example.com/b.png', $content, 'image/jpeg');

    $stats = $cache->getStats();

    expect($stats['count'])->toBe(2);
    expect($stats['size'])->toBe(2000);
    expect($stats['oldest'])->not->toBeNull();
    expect($stats['newest'])->not->toBeNull();
});

test('get stats returns zero for non file driver', function (): void {
    Config::set('proxy.cache.driver', 'redis');
    $cache = new ImageCache();

    $stats = $cache->getStats();

    expect($stats['count'])->toBe(0);
});

// --- Calculate Cache Size ---

test('calculate cache size', function (): void {
    $cache = new ImageCache();
    $content = str_repeat('a', 500);

    $cache->put('https://example.com/a.png', $content, 'image/png');
    $cache->put('https://example.com/b.png', $content, 'image/jpeg');

    $size = $cache->calculateCacheSize();

    expect($size)->toBe(1000);
});

// --- Lock ---

test('acquire and release lock', function (): void {
    $cache = new ImageCache();

    $lock = $cache->acquireLock('https://example.com/image.png', 1.0);

    expect($lock)->not->toBeNull();

    $cache->releaseLock($lock);
});

test('release lock handles null', function (): void {
    $cache = new ImageCache();

    // Should not throw
    $cache->releaseLock(null);
    expect(true)->toBeTrue();
});

// --- Size Enforcement ---

test('cleanup enforces size limit', function (): void {
    Config::set('proxy.cache.max_size', 500);
    $cache = new ImageCache();

    // Put entries that exceed the limit
    $cache->put('https://example.com/a.png', str_repeat('a', 300), 'image/png');
    sleep(1); // Ensure different mtime
    $cache->put('https://example.com/b.png', str_repeat('b', 300), 'image/png');

    $result = $cache->cleanup(expiredOnly: false);

    // Should have removed the oldest entry to get under limit
    expect($result['removed'])->toBeGreaterThanOrEqual(1);
});
