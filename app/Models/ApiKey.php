<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model representing an API key for proxy authentication.
 *
 * API keys are generated with a cryptographically random 64-character hex string.
 * Only the SHA-256 hash is stored in the database; the plaintext key is shown
 * once at creation time and cannot be retrieved afterwards.
 *
 * @property int                        $id
 * @property string                     $name
 * @property string                     $key        SHA-256 hash of the API key
 * @property int|null                   $rate_limit Requests per window for this key, null for the global default
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ApiKey extends Model
{
    /** @var string|null Plaintext key, only available immediately after generate() */
    private ?string $plainKey = null;
    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'key',
        'rate_limit',
        'last_used_at',
        'revoked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rate_limit' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @var array<int, string> */
    protected $hidden = [
        'key',
    ];

    /**
     * Generate a new API key with a cryptographically random value.
     *
     * The plaintext key is available via getPlainKey() immediately after
     * creation. Only the SHA-256 hash is stored in the database.
     *
     * @param string   $name      A descriptive name for the API key
     * @param int|null $rateLimit Requests per window for this key, null for the global default
     * @return self The newly created ApiKey model instance
     */
    public static function generate(string $name, ?int $rateLimit = null): self
    {
        $plainKey = bin2hex(random_bytes(32));

        $apiKey = self::create([
            'name' => $name,
            'key' => hash('sha256', $plainKey),
            'rate_limit' => $rateLimit,
        ]);

        $apiKey->plainKey = $plainKey;

        return $apiKey;
    }

    /**
     * Get the plaintext key (only available after generate()).
     *
     * @return string|null The plaintext API key, or null if not available
     */
    public function getPlainKey(): ?string
    {
        return $this->plainKey;
    }

    /**
     * Find an active (non-revoked) API key by its plaintext value.
     *
     * Hashes the input before querying, since only hashes are stored.
     *
     * @param string $key The raw API key string to look up
     * @return self|null The matching ApiKey, or null if not found or revoked
     */
    public static function findByKey(string $key): ?self
    {
        return self::where('key', hash('sha256', $key))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Check whether this API key is still valid (not revoked).
     *
     * @return bool True if the key has not been revoked
     */
    public function isValid(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Revoke this API key by setting the revoked_at timestamp.
     *
     * @return void
     */
    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    /**
     * Update the last_used_at timestamp to the current time.
     *
     * Throttled to avoid a DB write on every single request — only updates
     * if the last recorded usage is older than 5 minutes (or never set).
     *
     * @return void
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->diffInMinutes(now()) < 5) {
            return;
        }

        $this->update(['last_used_at' => now()]);
    }

    /**
     * Scope query to only include active (non-revoked) API keys.
     *
     * @param Builder $query The Eloquent query builder
     * @return Builder The scoped query builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Scope query to only include revoked API keys.
     *
     * @param Builder $query The Eloquent query builder
     * @return Builder The scoped query builder
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }
}
