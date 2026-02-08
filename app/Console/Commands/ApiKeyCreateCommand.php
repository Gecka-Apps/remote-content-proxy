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
 * Artisan command to create a new API key for proxy authentication.
 *
 * Generates a cryptographically random API key, associates it with
 * the provided descriptive name, and displays the key once for the
 * operator to store securely.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ApiKeyCreateCommand extends Command
{
    /** @var string */
    protected $signature = 'apikey:create
                            {name : A descriptive name for this API key}
                            {--rate-limit= : Requests per window for this key (default: the global PROXY_RATE_LIMIT_MAX_REQUESTS)}';

    /** @var string */
    protected $description = 'Create a new API key';

    /**
     * Execute the console command.
     *
     * Creates a new API key with the given name and displays it to the operator.
     *
     * @return int The exit status code (SUCCESS or FAILURE)
     */
    public function handle(): int
    {
        $name = $this->argument('name');
        $rateLimit = $this->option('rate-limit');

        if ($rateLimit !== null && (!ctype_digit($rateLimit) || (int) $rateLimit < 1)) {
            $this->error('The rate limit must be a positive number of requests per window.');
            return self::FAILURE;
        }

        $apiKey = ApiKey::generate($name, $rateLimit === null ? null : (int) $rateLimit);

        $plainKey = $apiKey->getPlainKey();

        $this->info('API key created successfully!');
        $this->newLine();
        $this->line("Name:  <comment>{$apiKey->name}</comment>");
        $this->line("Key:   <comment>{$plainKey}</comment>");
        $this->line("Limit: <comment>" . ($apiKey->rate_limit ?? 'default') . "</comment> requests per window");
        $this->newLine();
        $this->warn('Store this key securely. It will not be shown again.');

        return self::SUCCESS;
    }
}
