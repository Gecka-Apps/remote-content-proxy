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

/**
 * Artisan command to display proxy cache statistics.
 *
 * Shows item count, total size, max size, driver, and TTL.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class CacheInfoCommand extends Command
{
    /** @var string The console command signature */
    protected $signature = 'proxy:cache:info';

    /** @var string The console command description */
    protected $description = 'Display proxy cache statistics';

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
     * @return int Exit code
     */
    public function handle(): int
    {
        if ($this->cache->getDriver() !== 'file') {
            $this->warn('Detailed cache info is only available for the file driver.');
            $this->line(sprintf('  Cache driver: %s', $this->cache->getDriver()));
            $this->line(sprintf(
                '  Cache TTL: %d seconds (%d hours)',
                $this->cache->getTtl(),
                $this->cache->getTtl() / 3600,
            ));
            return self::SUCCESS;
        }

        $stats = $this->cache->getStats();

        $this->info('Proxy Cache Information:');
        $this->line(sprintf('  Total items: %d', $stats['count']));
        $this->line(sprintf('  Total size: %.2f MB', $stats['size'] / (1024 * 1024)));
        $this->line(sprintf('  Max size: %.2f MB', $this->cache->getMaxSize() / (1024 * 1024)));
        $this->line(sprintf('  Cache driver: %s', $this->cache->getDriver()));
        $this->line(sprintf('  Cache path: %s', $this->cache->getCachePath()));
        $this->line(sprintf(
            '  Cache TTL: %d seconds (%d hours)',
            $this->cache->getTtl(),
            $this->cache->getTtl() / 3600,
        ));

        if ($stats['oldest'] !== null) {
            $this->line(sprintf('  Oldest entry: %s', date('Y-m-d H:i:s', $stats['oldest'])));
        }
        if ($stats['newest'] !== null) {
            $this->line(sprintf('  Newest entry: %s', date('Y-m-d H:i:s', $stats['newest'])));
        }

        return self::SUCCESS;
    }
}
