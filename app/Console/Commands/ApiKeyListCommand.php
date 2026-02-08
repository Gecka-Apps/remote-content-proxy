<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

/**
 * Artisan command to list API keys.
 *
 * Displays a table of API keys with their ID, name, creation date,
 * last usage, and status. Supports filtering to show only active,
 * only revoked, or all keys.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ApiKeyListCommand extends Command
{
    /** @var string */
    protected $signature = 'apikey:list {--revoked : Show only revoked keys} {--all : Show all keys including revoked}';

    /** @var string */
    protected $description = 'List all API keys';

    /**
     * Execute the console command.
     *
     * Queries API keys based on the provided filter options and renders
     * them as a table in the console.
     *
     * @return int The exit status code (SUCCESS or FAILURE)
     */
    public function handle(): int
    {
        $query = ApiKey::query()->orderBy('created_at', 'desc');

        if ($this->option('revoked')) {
            $query->revoked();
        } elseif (!$this->option('all')) {
            $query->active();
        }

        $keys = $query->get();

        if ($keys->isEmpty()) {
            $this->info('No API keys found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Rate limit', 'Created', 'Last Used', 'Status'],
            $keys->map(fn(ApiKey $key) => [
                $key->id,
                $key->name,
                $key->rate_limit ?? 'default',
                $key->created_at->format('Y-m-d H:i'),
                $key->last_used_at?->format('Y-m-d H:i') ?? 'Never',
                $key->revoked_at ? 'Revoked' : 'Active',
            ]),
        );

        return self::SUCCESS;
    }
}
