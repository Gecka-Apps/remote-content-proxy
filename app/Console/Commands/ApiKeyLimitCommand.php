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
 * Artisan command to set or clear the rate limit of an API key.
 *
 * A key without a limit of its own falls back to the global
 * PROXY_RATE_LIMIT_MAX_REQUESTS; the window length is global either way.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ApiKeyLimitCommand extends Command
{
    /** @var string */
    protected $signature = 'apikey:limit
                            {id : The ID of the API key}
                            {max : Requests per window for this key, or "default" to use the global limit}';

    /** @var string */
    protected $description = 'Set the rate limit of an API key';

    /**
     * Execute the console command.
     *
     * @return int The exit status code (SUCCESS or FAILURE)
     */
    public function handle(): int
    {
        $id = $this->argument('id');
        $max = $this->argument('max');

        $apiKey = ApiKey::find($id);

        if ($apiKey === null) {
            $this->error("API key with ID {$id} not found.");
            return self::FAILURE;
        }

        if ($max === 'default') {
            $apiKey->update(['rate_limit' => null]);
            $this->info("API key '{$apiKey->name}' now uses the global rate limit.");
            return self::SUCCESS;
        }

        if (!ctype_digit($max) || (int) $max < 1) {
            $this->error('The rate limit must be a positive number of requests per window, or "default".');
            return self::FAILURE;
        }

        $apiKey->update(['rate_limit' => (int) $max]);
        $this->info("API key '{$apiKey->name}' is now limited to {$max} requests per window.");

        return self::SUCCESS;
    }
}
