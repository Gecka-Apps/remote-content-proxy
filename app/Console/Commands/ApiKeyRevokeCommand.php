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
 * Artisan command to revoke an existing API key.
 *
 * Looks up the API key by its database ID, confirms the action
 * with the operator, and marks the key as revoked so it can no
 * longer be used for authentication.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ApiKeyRevokeCommand extends Command
{
    /** @var string */
    protected $signature = 'apikey:revoke {id : The ID of the API key to revoke}';

    /** @var string */
    protected $description = 'Revoke an API key';

    /**
     * Execute the console command.
     *
     * Finds the API key by ID, checks if it is already revoked,
     * asks for confirmation, and then revokes it.
     *
     * @return int The exit status code (SUCCESS or FAILURE)
     */
    public function handle(): int
    {
        $id = $this->argument('id');

        $apiKey = ApiKey::find($id);

        if ($apiKey === null) {
            $this->error("API key with ID {$id} not found.");
            return self::FAILURE;
        }

        if ($apiKey->revoked_at !== null) {
            $this->warn("API key '{$apiKey->name}' is already revoked.");
            return self::SUCCESS;
        }

        if (!$this->confirm("Are you sure you want to revoke the API key '{$apiKey->name}'?")) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $apiKey->revoke();

        $this->info("API key '{$apiKey->name}' has been revoked.");

        return self::SUCCESS;
    }
}
