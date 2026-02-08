<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ImageCache;

/**
 * Artisan command for scheduled cache maintenance.
 *
 * Performs multi-step maintenance: removes expired entries, enforces
 * the configured size limit via LRU eviction, cleans orphaned metadata
 * files, and removes stale lock files. Supports dry-run mode and
 * detailed output.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class MaintainProxyCacheCommand extends Command
{
    /** @var string */
    protected $signature = 'proxy:cache:maintain {--dry-run : Show what would be done without making changes} {--details : Show detailed information}';

    /** @var string */
    protected $description = 'Maintain the image proxy cache by cleaning expired items and checking size limits';

    /**
     * Create a new command instance.
     *
     * @param ImageCache $cache The image cache service
     */
    public function __construct(private ImageCache $cache)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Runs all maintenance steps sequentially and displays a summary
     * of actions taken (or that would be taken in dry-run mode).
     *
     * @return int The exit status code (SUCCESS or FAILURE)
     */
    public function handle(): int
    {
        if ($this->cache->getDriver() !== 'file') {
            $this->error('Cache maintenance is only supported for file driver.');
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $showDetails = $this->option('details');

        $this->info('Starting proxy cache maintenance...');

        // Step 1: Clean expired items
        $expiredStats = $this->cleanExpiredItems($dryRun, $showDetails);

        // Step 2: Check cache size and clean if needed
        $sizeStats = $this->checkCacheSize($dryRun, $showDetails);

        // Step 3: Clean orphaned files
        $orphanStats = $this->cleanOrphanedFiles($dryRun, $showDetails);

        // Step 4: Clean stale lock files
        $lockStats = $this->cleanStaleLockFiles($dryRun, $showDetails);

        // Summary
        $this->info('\nCache maintenance completed:');
        $this->line(sprintf('  Expired items removed: %d', $expiredStats['removed']));
        $this->line(sprintf('  Expired items checked: %d', $expiredStats['checked']));
        $this->line(sprintf('  Size cleanup removed: %d files', $sizeStats['removed']));
        $this->line(sprintf('  Orphaned files removed: %d', $orphanStats['removed']));
        $this->line(sprintf('  Stale lock files removed: %d', $lockStats['removed']));
        $this->line(sprintf('  Current cache size: %.2f MB', $sizeStats['current_size_mb']));
        $this->line(sprintf('  Max cache size: %.2f MB', $sizeStats['max_size_mb']));

        if ($dryRun) {
            $this->warn('This was a dry run. No changes were actually made.');
        }

        return self::SUCCESS;
    }

    /**
     * Clean expired and orphaned (missing metadata) cache items.
     *
     * Iterates all proxy_* files, checks their metadata for expiration,
     * and removes expired entries. Data files without a companion meta
     * file are treated as orphans and also removed.
     *
     * @param bool $dryRun      When true, only report what would be done
     * @param bool $showDetails When true, print per-file details
     * @return array{checked: int, removed: int} Statistics of the operation
     */
    private function cleanExpiredItems(bool $dryRun, bool $showDetails): array
    {
        $cachePath = $this->cache->getCachePath();
        $files = glob($cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

        if ($files === false) {
            if ($showDetails) {
                $this->info('No cache files found.');
            }
            return ['checked' => 0, 'removed' => 0];
        }

        $removedCount = 0;
        $checkedCount = 0;
        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $checkedCount++;

            $fileInfo = pathinfo($file);
            $metaFile = $fileInfo['dirname'] . DIRECTORY_SEPARATOR . $fileInfo['filename'] . '.meta';

            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);

                if (isset($meta['expires_at']) && $meta['expires_at'] < $now) {
                    if ($showDetails) {
                        $this->line(sprintf('Would remove expired file: %s', basename($file)));
                    }

                    if (!$dryRun) {
                        unlink($file);
                        unlink($metaFile);
                        $removedCount++;
                    }
                }
            } else {
                // Orphaned data file (no meta)
                if ($showDetails) {
                    $this->line(sprintf('Would remove orphaned data file: %s', basename($file)));
                }

                if (!$dryRun) {
                    unlink($file);
                    $removedCount++;
                }
            }
        }

        if ($showDetails && $removedCount > 0) {
            $this->info(sprintf('Removed %d expired/orphaned items.', $removedCount));
        }

        return ['checked' => $checkedCount, 'removed' => $removedCount];
    }

    /**
     * Check cache size against the configured limit and evict oldest files.
     *
     * Calculates the current total size of all cache files, then removes
     * the oldest files (by modification time) until the total falls within
     * the configured maximum.
     *
     * @param bool $dryRun      When true, only report what would be done
     * @param bool $showDetails When true, print per-file details
     * @return array{removed: int, current_size_mb: float, max_size_mb: float} Statistics of the operation
     */
    private function checkCacheSize(bool $dryRun, bool $showDetails): array
    {
        $cachePath = $this->cache->getCachePath();
        $maxSize = $this->cache->getMaxSize();

        $files = glob($cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

        if ($files === false) {
            return ['removed' => 0, 'current_size_mb' => 0, 'max_size_mb' => $maxSize / (1024 * 1024)];
        }

        $currentSize = 0;
        $fileSizes = [];

        // Calculate current size and collect file info
        foreach ($files as $file) {
            if (is_file($file)) {
                $size = filesize($file);
                $currentSize += $size;
                $fileSizes[] = ['path' => $file, 'size' => $size, 'mtime' => filemtime($file)];
            }
        }

        $removedCount = 0;

        if ($currentSize > $maxSize) {
            if ($showDetails) {
                $this->info(sprintf(
                    'Cache size limit exceeded: %.2f MB > %.2f MB',
                    $currentSize / (1024 * 1024),
                    $maxSize / (1024 * 1024),
                ));
            }

            // Sort files by modification time (oldest first)
            usort($fileSizes, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

            // Remove oldest files until we're under the limit
            foreach ($fileSizes as $fileInfo) {
                if ($currentSize <= $maxSize) {
                    break;
                }

                if ($showDetails) {
                    $this->line(sprintf(
                        'Would remove old file to free space: %s (%.2f KB)',
                        basename($fileInfo['path']),
                        $fileInfo['size'] / 1024,
                    ));
                }

                if (!$dryRun) {
                    unlink($fileInfo['path']);

                    // Also remove corresponding meta file
                    $metaFile = pathinfo($fileInfo['path'], PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR
                               . pathinfo($fileInfo['path'], PATHINFO_FILENAME) . '.meta';
                    if (file_exists($metaFile)) {
                        unlink($metaFile);
                    }

                    $removedCount++;
                    $currentSize -= $fileInfo['size'];
                }
            }

            if ($showDetails && $removedCount > 0) {
                $this->info(sprintf('Removed %d old files to enforce size limit.', $removedCount));
            }
        }

        return [
            'removed' => $removedCount,
            'current_size_mb' => $currentSize / (1024 * 1024),
            'max_size_mb' => $maxSize / (1024 * 1024),
        ];
    }

    /**
     * Clean orphaned metadata files that have no corresponding data file.
     *
     * Separates data files from .meta files, then checks each .meta file
     * for a matching data file. Orphaned .meta files are removed.
     *
     * @param bool $dryRun      When true, only report what would be done
     * @param bool $showDetails When true, print per-file details
     * @return array{removed: int} Statistics of the operation
     */
    private function cleanOrphanedFiles(bool $dryRun, bool $showDetails): array
    {
        $cachePath = $this->cache->getCachePath();
        $files = glob($cachePath . DIRECTORY_SEPARATOR . 'proxy_*');

        if ($files === false) {
            return ['removed' => 0];
        }

        $removedCount = 0;
        $dataFiles = [];
        $metaFiles = [];

        // Separate data files and meta files
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (str_contains(basename($file), '.meta')) {
                $metaFiles[] = $file;
            } else {
                $dataFiles[] = $file;
            }
        }

        // Check for meta files without corresponding data files
        foreach ($metaFiles as $metaFile) {
            $expectedDataFile = str_replace('.meta', '', $metaFile);

            if (!in_array($expectedDataFile, $dataFiles)) {
                if ($showDetails) {
                    $this->line(sprintf('Would remove orphaned meta file: %s', basename($metaFile)));
                }

                if (!$dryRun) {
                    unlink($metaFile);
                    $removedCount++;
                }
            }
        }

        return ['removed' => $removedCount];
    }

    /**
     * Clean stale lock files older than 5 minutes.
     *
     * Lock files that remain after the holding process has ended (e.g.
     * due to a crash) are considered stale and are safe to remove.
     *
     * @param bool $dryRun      When true, only report what would be done
     * @param bool $showDetails When true, print per-file details
     * @return array{removed: int} Statistics of the operation
     */
    private function cleanStaleLockFiles(bool $dryRun, bool $showDetails): array
    {
        $cachePath = $this->cache->getCachePath();
        $lockFiles = glob($cachePath . DIRECTORY_SEPARATOR . 'proxy_*.lock');

        if ($lockFiles === false) {
            return ['removed' => 0];
        }

        $removedCount = 0;
        $staleThreshold = time() - 300;

        foreach ($lockFiles as $lockFile) {
            if (!is_file($lockFile)) {
                continue;
            }

            $mtime = filemtime($lockFile);

            if ($mtime < $staleThreshold) {
                if ($showDetails) {
                    $this->line(sprintf(
                        'Would remove stale lock file: %s (age: %d seconds)',
                        basename($lockFile),
                        time() - $mtime,
                    ));
                }

                if (!$dryRun) {
                    @unlink($lockFile);
                    $removedCount++;
                }
            }
        }

        if ($showDetails && $removedCount > 0) {
            $this->info(sprintf('Removed %d stale lock files.', $removedCount));
        }

        return ['removed' => $removedCount];
    }
}
