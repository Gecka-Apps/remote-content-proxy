<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Services\ImageCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to clear the image proxy cache.
 *
 * Supports clearing all cache entries or only expired entries.
 * Use proxy:cache:info to view cache statistics.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ClearProxyCacheCommand extends Command
{
    /** @var string The console command signature */
    protected $signature = 'proxy:cache:clear {--expired : Only clear expired cache items} {--force : Force clearance without confirmation}';

    /** @var string The console command description */
    protected $description = 'Clear the image proxy cache (all entries or expired only)';

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
     * With --expired, removes only expired entries.
     * Without options, clears all entries (with confirmation unless --force).
     *
     * @return int The exit status code
     */
    public function handle(): int
    {
        if ($this->option('expired')) {
            $result = $this->cache->cleanup(expiredOnly: true);
            $this->info(sprintf('Expired cache cleanup completed. %d items removed.', $result['removed']));
            return self::SUCCESS;
        }

        // Default: clear all (with confirmation)
        if (!$this->option('force') && !$this->confirm('Are you sure you want to clear the entire proxy cache?')) {
            $this->info('Cache clearance cancelled.');
            return self::SUCCESS;
        }

        $start = microtime(true);
        $this->cache->clear();
        $time = microtime(true) - $start;

        $this->info('Proxy cache cleared completely in ' . round($time, 2) . ' seconds.');
        Log::info('Proxy cache cleared completely');

        return self::SUCCESS;
    }
}
