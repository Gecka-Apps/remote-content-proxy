<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Image caching service supporting file and Redis storage drivers.
 *
 * Handles reading, writing, and lifecycle management of cached proxy content.
 * Supports cache stampede prevention via locking, LRU eviction, and automatic
 * size-based cleanup scheduling.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ImageCache
{
    private bool $enabled;
    private string $driver;
    private int $ttl;
    private string $cachePath;
    private int $maxCacheSize;
    private int $cleanupThreshold;

    /**
     * Initialize the image cache service from configuration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->enabled = config('proxy.cache.enabled');
        $this->driver = config('proxy.cache.driver');
        $this->ttl = config('proxy.cache.ttl');
        $this->cachePath = config('proxy.cache.path');
        $this->maxCacheSize = config('proxy.cache.max_size');
        $this->cleanupThreshold = config('proxy.cache.cleanup_threshold');

        $this->ensureCacheDirectoryExists();
    }

    /**
     * Ensure the file-based cache directory exists, creating it if necessary.
     *
     * @return void
     */
    private function ensureCacheDirectoryExists(): void
    {
        if ($this->driver === 'file' && !file_exists($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Get cached image data for the given URL.
     *
     * @param string $url The original resource URL used as cache key basis
     * @return array{content: string, content_type: string}|null Cached data or null on miss
     */
    public function get(string $url): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $cacheKey = $this->generateCacheKey($url);

        if ($this->driver === 'file') {
            return $this->getFromFile($cacheKey);
        } elseif ($this->driver === 'redis') {
            return $this->getFromRedis($cacheKey);
        }

        return null;
    }

    /**
     * Acquire a lock for a specific URL to prevent cache stampede.
     * Uses Laravel Cache lock which supports both file and Redis drivers.
     *
     * @param string $url     The URL to lock on
     * @param float  $timeout Maximum seconds to wait for the lock
     * @return Lock|null The acquired lock instance, or null on failure
     */
    public function acquireLock(string $url, float $timeout = 5.0): ?Lock
    {
        $cacheKey = $this->generateCacheKey($url);
        $lock = Cache::lock('lock:' . $cacheKey, 30);

        try {
            if ($lock->block((int) $timeout)) {
                return $lock;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to acquire cache lock', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Release a previously acquired lock.
     *
     * @param Lock|null $lock The lock to release, or null (no-op)
     * @return void
     */
    public function releaseLock(?Lock $lock): void
    {
        $lock?->release();
    }

    /**
     * Get cached data from file storage.
     *
     * Reads the binary content file and its associated JSON metadata file,
     * validates expiration, and returns the cached entry if still valid.
     *
     * @param string $cacheKey The hashed cache key
     * @return array{content: string, content_type: string}|null Cached data or null on miss/expiry
     */
    private function getFromFile(string $cacheKey): ?array
    {
        $filePath = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey;
        $metaFile = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey . '.meta';

        if (!file_exists($filePath) || !file_exists($metaFile)) {
            return null;
        }

        $metaContent = file_get_contents($metaFile);
        if ($metaContent === false) {
            return null;
        }

        $meta = json_decode($metaContent, true);
        if (!is_array($meta)) {
            return null;
        }

        // Check expiration
        if (!isset($meta['expires_at']) || $meta['expires_at'] <= time()) {
            // Expired - clean up
            $this->deleteFile($filePath);
            $this->deleteFile($metaFile);
            return null;
        }

        // Check content type exists
        if (!isset($meta['content_type'])) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        return [
            'content' => $content,
            'content_type' => $meta['content_type'],
        ];
    }

    /**
     * Get cached data from Redis.
     *
     * Retrieves the JSON-encoded cache entry from Redis, validates expiration,
     * and decodes the base64-encoded binary content.
     *
     * @param string $cacheKey The hashed cache key
     * @return array{content: string, content_type: string}|null Cached data or null on miss/error
     */
    private function getFromRedis(string $cacheKey): ?array
    {
        try {
            $redis = app('redis');
            $cached = $redis->get($cacheKey);

            if (!$cached) {
                return null;
            }

            $data = json_decode($cached, true);

            if (!is_array($data) || !isset($data['content'], $data['content_type'])) {
                return null;
            }

            // Check expiration (Redis TTL handles this, but double-check)
            if (isset($data['expires_at']) && $data['expires_at'] <= time()) {
                return null;
            }

            $content = base64_decode($data['content'], true);
            if ($content === false) {
                return null;
            }

            return [
                'content' => $content,
                'content_type' => $data['content_type'],
            ];
        } catch (\Exception $e) {
            Log::warning('Redis cache get error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Store image content in the cache.
     *
     * @param string $url         The original resource URL
     * @param string $content     The binary content to cache
     * @param string $contentType The MIME type of the content
     * @return void
     */
    public function put(string $url, string $content, string $contentType): void
    {
        if (!$this->enabled) {
            return;
        }

        $cacheKey = $this->generateCacheKey($url);
        $expiresAt = time() + $this->ttl;

        if ($this->driver === 'file') {
            $this->putToFile($cacheKey, $content, $contentType, $expiresAt);
        } elseif ($this->driver === 'redis') {
            $this->putToRedis($cacheKey, $content, $contentType, $expiresAt);
        }
    }

    /**
     * Store content in the file-based cache.
     *
     * Writes the binary content to a data file and creates a companion JSON
     * metadata file containing content type, expiration, size, and creation time.
     * Each file lands through a rename from a dot-prefixed temporary, so a
     * concurrent reader sees either the previous entry or the complete new
     * one, and a write cut short by a full disk leaves nothing behind that
     * the proxy_* globs would pick up.
     *
     * @param string $cacheKey    The hashed cache key
     * @param string $content     The binary content to store
     * @param string $contentType The MIME type of the content
     * @param int    $expiresAt   Unix timestamp when this entry expires
     * @return void
     */
    private function putToFile(string $cacheKey, string $content, string $contentType, int $expiresAt): void
    {
        $filePath = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey;
        $metaFile = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey . '.meta';

        if (!$this->writeAtomically($filePath, $content)) {
            Log::warning('Failed to write cache file', ['cache_key' => $cacheKey]);
            return;
        }

        $meta = json_encode([
            'content_type' => $contentType,
            'expires_at' => $expiresAt,
            'size' => strlen($content),
            'created_at' => time(),
        ]);

        if (!$this->writeAtomically($metaFile, $meta)) {
            Log::warning('Failed to write cache metadata', ['cache_key' => $cacheKey]);
            $this->deleteFile($filePath);
            return;
        }

        // Schedule cleanup if needed (non-blocking check)
        $this->maybeScheduleCleanup();
    }

    /**
     * Write a file through a temporary sibling and a rename.
     *
     * @param string $path    Final path of the file
     * @param string $content Bytes to write
     * @return bool True when the file is in place with all its bytes
     */
    private function writeAtomically(string $path, string $content): bool
    {
        $tmpPath = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . uniqid('', true) . '.tmp';

        $written = file_put_contents($tmpPath, $content);
        if ($written !== strlen($content) || !rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }

    /**
     * Store content in the Redis cache.
     *
     * Encodes the binary content as base64 and stores it as a JSON payload
     * with a Redis TTL matching the configured cache TTL.
     *
     * @param string $cacheKey    The hashed cache key
     * @param string $content     The binary content to store
     * @param string $contentType The MIME type of the content
     * @param int    $expiresAt   Unix timestamp when this entry expires
     * @return void
     */
    private function putToRedis(string $cacheKey, string $content, string $contentType, int $expiresAt): void
    {
        try {
            $redis = app('redis');
            $redis->setex($cacheKey, $this->ttl, json_encode([
                'content' => base64_encode($content),
                'content_type' => $contentType,
                'expires_at' => $expiresAt,
            ]));
        } catch (\Exception $e) {
            Log::warning('Redis cache put error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Generate a collision-resistant cache key using SHA-256.
     *
     * @param string $url The URL to hash
     * @return string The prefixed SHA-256 hash of the URL
     */
    private function generateCacheKey(string $url): string
    {
        return 'proxy_' . hash('sha256', $url);
    }

    /**
     * Get the configured cache storage driver name.
     *
     * @return string The driver name (e.g. 'file', 'redis')
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get the file-based cache storage directory path.
     *
     * @return string The absolute path to the cache directory
     */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Get the configured cache TTL in seconds.
     *
     * @return int The time-to-live in seconds
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Get the configured maximum cache size in bytes.
     *
     * @return int The maximum cache size in bytes
     */
    public function getMaxSize(): int
    {
        return $this->maxCacheSize;
    }

    /**
     * Check if cleanup is needed and perform it inline.
     *
     * Uses a marker file to throttle size checks to every 5 minutes.
     * When the cache exceeds the configured threshold, expired entries
     * are removed and LRU eviction is triggered automatically.
     *
     * @return void
     */
    private function maybeScheduleCleanup(): void
    {
        $markerFile = $this->cachePath . DIRECTORY_SEPARATOR . '.last_size_check';

        if (file_exists($markerFile)) {
            $lastCheck = (int) file_get_contents($markerFile);
            if (time() - $lastCheck < 300) {
                return;
            }
        }

        file_put_contents($markerFile, (string) time());

        $currentSize = $this->estimateCacheSize();
        $threshold = $this->maxCacheSize * ($this->cleanupThreshold / 100);

        if ($currentSize > $threshold) {
            Log::info('Cache size exceeds threshold, running inline cleanup', [
                'current_size' => $currentSize,
                'threshold' => $threshold,
                'max_size' => $this->maxCacheSize,
            ]);

            $result = $this->cleanup();

            if ($result['removed'] > 0) {
                Log::info('Inline cache cleanup completed', [
                    'removed' => $result['removed'],
                    'freed' => $result['freed'],
                ]);
            }
        }
    }

    /**
     * Estimate cache size without iterating all files.
     *
     * Uses a cached size file if available; falls back to full calculation.
     *
     * @return int The estimated cache size in bytes
     */
    private function estimateCacheSize(): int
    {
        $sizeFile = $this->cachePath . DIRECTORY_SEPARATOR . '.cache_size';

        if (file_exists($sizeFile)) {
            return (int) file_get_contents($sizeFile);
        }

        // Full calculation if no estimate exists
        return $this->calculateCacheSize();
    }

    /**
     * Calculate the actual total cache size by summing all data files.
     *
     * Persists the calculated size to a marker file for future estimates.
     *
     * @return int The total cache size in bytes
     */
    public function calculateCacheSize(): int
    {
        $currentSize = 0;
        $files = glob($this->cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file) && !str_ends_with($file, '.meta')) {
                $currentSize += filesize($file);
            }
        }

        // Cache the size estimate
        file_put_contents(
            $this->cachePath . DIRECTORY_SEPARATOR . '.cache_size',
            (string) $currentSize,
        );

        return $currentSize;
    }

    /**
     * Perform cache cleanup - called by scheduled command.
     *
     * Removes expired entries first, then optionally enforces the size limit
     * using LRU eviction.
     *
     * @param bool $expiredOnly When true, only remove expired entries without enforcing size limit
     * @return array{removed: int, freed: int} Number of items removed and bytes freed
     */
    public function cleanup(bool $expiredOnly = false): array
    {
        if ($this->driver !== 'file') {
            return ['removed' => 0, 'freed' => 0];
        }

        $removed = 0;
        $freed = 0;
        $files = glob($this->cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

        if ($files === false) {
            return ['removed' => 0, 'freed' => 0];
        }

        // Separate data files from meta files
        $dataFiles = array_filter($files, fn($f) => !str_ends_with($f, '.meta'));

        // First pass: remove expired entries
        foreach ($dataFiles as $file) {
            $metaFile = $file . '.meta';

            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);

                if (isset($meta['expires_at']) && $meta['expires_at'] <= time()) {
                    $size = filesize($file);
                    $this->deleteFile($file);
                    $this->deleteFile($metaFile);
                    $removed++;
                    $freed += $size;
                }
            } else {
                // Orphaned data file - remove it
                $size = filesize($file);
                $this->deleteFile($file);
                $removed++;
                $freed += $size;
            }
        }

        // Second pass: enforce size limit if not expired-only mode
        if (!$expiredOnly) {
            $currentSize = $this->calculateCacheSize();

            if ($currentSize > $this->maxCacheSize) {
                $result = $this->enforceSizeLimit($currentSize);
                $removed += $result['removed'];
                $freed += $result['freed'];
            }
        }

        // Update size estimate
        $this->calculateCacheSize();

        return ['removed' => $removed, 'freed' => $freed];
    }

    /**
     * Enforce cache size limit using LRU (Least Recently Used) eviction.
     *
     * Sorts all data files by modification time and removes the oldest until
     * the total cache size is within the configured maximum.
     *
     * @param int $currentSize The current total cache size in bytes
     * @return array{removed: int, freed: int} Number of items removed and bytes freed
     */
    private function enforceSizeLimit(int $currentSize): array
    {
        $removed = 0;
        $freed = 0;
        $files = glob($this->cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

        if ($files === false) {
            return ['removed' => 0, 'freed' => 0];
        }

        // Get data files with modification times
        $dataFiles = [];
        foreach ($files as $file) {
            if (!str_ends_with($file, '.meta') && is_file($file)) {
                $dataFiles[] = [
                    'path' => $file,
                    'mtime' => filemtime($file),
                    'size' => filesize($file),
                ];
            }
        }

        // Sort by modification time (oldest first - LRU)
        usort($dataFiles, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

        // Remove oldest files until under limit
        foreach ($dataFiles as $fileInfo) {
            if ($currentSize <= $this->maxCacheSize) {
                break;
            }

            $metaFile = $fileInfo['path'] . '.meta';

            $this->deleteFile($fileInfo['path']);
            $this->deleteFile($metaFile);

            $currentSize -= $fileInfo['size'];
            $removed++;
            $freed += $fileInfo['size'];
        }

        return ['removed' => $removed, 'freed' => $freed];
    }

    /**
     * Clear all cache entries for the configured driver.
     *
     * For file driver, removes all proxy_* files and internal marker files.
     * For Redis driver, deletes all proxy_* keys.
     *
     * @return void
     */
    public function clear(): void
    {
        if ($this->driver === 'file') {
            $files = glob($this->cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

            if ($files !== false) {
                foreach ($files as $file) {
                    $this->deleteFile($file);
                }
            }

            // Clear size estimate
            $this->deleteFile($this->cachePath . DIRECTORY_SEPARATOR . '.cache_size');
            $this->deleteFile($this->cachePath . DIRECTORY_SEPARATOR . '.last_size_check');
        } elseif ($this->driver === 'redis') {
            try {
                $redis = app('redis');
                $keys = $redis->keys('proxy_*');

                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } catch (\Exception $e) {
                Log::warning('Redis cache clear error', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Get cache statistics for the file-based driver.
     *
     * Returns the count of cached items, total size, and timestamps of the
     * oldest and newest entries.
     *
     * @return array{count: int, size: int, oldest: ?int, newest: ?int} Cache statistics
     */
    public function getStats(): array
    {
        if ($this->driver !== 'file') {
            return ['count' => 0, 'size' => 0, 'oldest' => null, 'newest' => null];
        }

        $files = glob($this->cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

        if ($files === false || empty($files)) {
            return ['count' => 0, 'size' => 0, 'oldest' => null, 'newest' => null];
        }

        $dataFiles = array_filter($files, fn($f) => !str_ends_with($f, '.meta'));
        $count = count($dataFiles);
        $size = 0;
        $oldest = PHP_INT_MAX;
        $newest = 0;

        foreach ($dataFiles as $file) {
            if (is_file($file)) {
                $size += filesize($file);
                $mtime = filemtime($file);
                $oldest = min($oldest, $mtime);
                $newest = max($newest, $mtime);
            }
        }

        return [
            'count' => $count,
            'size' => $size,
            'oldest' => $count > 0 ? $oldest : null,
            'newest' => $count > 0 ? $newest : null,
        ];
    }

    /**
     * Safely delete a file if it exists.
     *
     * @param string $path The absolute path to the file to delete
     * @return bool True if the file was successfully deleted, false otherwise
     */
    private function deleteFile(string $path): bool
    {
        if (file_exists($path) && is_file($path)) {
            return unlink($path);
        }
        return false;
    }
}
