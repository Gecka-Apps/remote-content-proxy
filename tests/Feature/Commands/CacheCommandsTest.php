<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $this->cachePath = sys_get_temp_dir() . '/proxy-cache-cmd-test-' . uniqid();
    mkdir($this->cachePath, 0755, true);

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

function createCacheEntry(string $cachePath, string $url, string $content, int $expiresAt): void
{
    $cacheKey = 'proxy_' . hash('sha256', $url);
    $filePath = $cachePath . DIRECTORY_SEPARATOR . $cacheKey;
    $metaFile = $filePath . '.meta';

    file_put_contents($filePath, $content);
    file_put_contents($metaFile, json_encode([
        'content_type' => 'image/png',
        'expires_at' => $expiresAt,
        'size' => strlen($content),
        'created_at' => time(),
    ]));
}

// --- proxy:cache:info ---

test('info command shows cache statistics', function (): void {
    $this->artisan('proxy:cache:info')
        ->assertExitCode(0)
        ->expectsOutputToContain('Proxy Cache Information');
});

test('info command shows item count', function (): void {
    createCacheEntry($this->cachePath, 'https://example.com/a.png', 'content-a', time() + 3600);
    createCacheEntry($this->cachePath, 'https://example.com/b.png', 'content-b', time() + 3600);

    $this->artisan('proxy:cache:info')
        ->assertExitCode(0)
        ->expectsOutputToContain('Total items: 2');
});

test('info command works for non file driver', function (): void {
    Config::set('proxy.cache.driver', 'redis');

    $this->artisan('proxy:cache:info')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cache driver: redis');
});

// --- proxy:cache:clear ---

test('clear default asks confirmation and clears all', function (): void {
    createCacheEntry($this->cachePath, 'https://example.com/a.png', 'content-a', time() + 3600);

    $this->artisan('proxy:cache:clear')
        ->expectsConfirmation(
            'Are you sure you want to clear the entire proxy cache?',
            'yes',
        )
        ->assertExitCode(0)
        ->expectsOutputToContain('cleared completely');

    $remaining = glob($this->cachePath . '/proxy_*');
    expect($remaining)->toBeEmpty();
});

test('clear default cancel', function (): void {
    createCacheEntry($this->cachePath, 'https://example.com/a.png', 'content-a', time() + 3600);

    $this->artisan('proxy:cache:clear')
        ->expectsConfirmation(
            'Are you sure you want to clear the entire proxy cache?',
            'no',
        )
        ->assertExitCode(0)
        ->expectsOutputToContain('cancelled');

    // Entry should still exist
    $key = 'proxy_' . hash('sha256', 'https://example.com/a.png');
    expect($this->cachePath . DIRECTORY_SEPARATOR . $key)->toBeFile();
});

test('clear expired removes expired entries', function (): void {
    createCacheEntry($this->cachePath, 'https://example.com/old.png', 'old', time() - 100);
    createCacheEntry($this->cachePath, 'https://example.com/fresh.png', 'fresh', time() + 3600);

    $this->artisan('proxy:cache:clear', ['--expired' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Expired cache cleanup completed');

    // Fresh entry should still exist
    $freshKey = 'proxy_' . hash('sha256', 'https://example.com/fresh.png');
    expect($this->cachePath . DIRECTORY_SEPARATOR . $freshKey)->toBeFile();

    // Expired entry should be gone
    $oldKey = 'proxy_' . hash('sha256', 'https://example.com/old.png');
    expect($this->cachePath . DIRECTORY_SEPARATOR . $oldKey)->not->toBeFile();
});

test('clear force removes everything without confirmation', function (): void {
    createCacheEntry($this->cachePath, 'https://example.com/a.png', 'content-a', time() + 3600);
    createCacheEntry($this->cachePath, 'https://example.com/b.png', 'content-b', time() + 3600);

    $this->artisan('proxy:cache:clear', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('cleared completely');

    $remaining = glob($this->cachePath . '/proxy_*');
    expect($remaining)->toBeEmpty();
});

// --- proxy:cache:maintain ---

test('maintain command runs successfully', function (): void {
    $this->artisan('proxy:cache:maintain')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cache maintenance completed');
});

test('maintain removes expired items', function (): void {
    createCacheEntry($this->cachePath, 'https://example.com/expired.png', 'expired', time() - 100);

    $this->artisan('proxy:cache:maintain')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cache maintenance completed');

    $key = 'proxy_' . hash('sha256', 'https://example.com/expired.png');
    expect($this->cachePath . DIRECTORY_SEPARATOR . $key)->not->toBeFile();
});

test('maintain dry run does not remove files', function (): void {
    createCacheEntry($this->cachePath, 'https://example.com/expired.png', 'expired', time() - 100);

    $this->artisan('proxy:cache:maintain', ['--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('dry run');

    // File should still exist
    $key = 'proxy_' . hash('sha256', 'https://example.com/expired.png');
    expect($this->cachePath . DIRECTORY_SEPARATOR . $key)->toBeFile();
});

test('maintain cleans orphaned meta files', function (): void {
    // Create a meta file without a corresponding data file
    $metaFile = $this->cachePath . DIRECTORY_SEPARATOR . 'proxy_orphaned.meta';
    file_put_contents($metaFile, json_encode([
        'content_type' => 'image/png',
        'expires_at' => time() + 3600,
    ]));

    $this->artisan('proxy:cache:maintain')
        ->assertExitCode(0);

    expect($metaFile)->not->toBeFile();
});

test('maintain fails for non file driver', function (): void {
    Config::set('proxy.cache.driver', 'redis');

    $this->artisan('proxy:cache:maintain')
        ->assertExitCode(1)
        ->expectsOutputToContain('only supported for file driver');
});
