<?php

declare(strict_types=1);

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\{assertDatabaseCount, assertDatabaseHas};

uses(RefreshDatabase::class);

// --- apikey:create ---

test('create command creates api key', function (): void {
    $this->artisan('apikey:create', ['name' => 'Test Key'])
        ->assertExitCode(0)
        ->expectsOutputToContain('API key created successfully');

    assertDatabaseCount('api_keys', 1);
    assertDatabaseHas('api_keys', ['name' => 'Test Key']);
});

test('create command outputs key', function (): void {
    $this->artisan('apikey:create', ['name' => 'My Key'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Key:');
});

test('create command accepts a rate limit', function (): void {
    $this->artisan('apikey:create', ['name' => 'Webmail', '--rate-limit' => '5000'])
        ->assertExitCode(0)
        ->expectsOutputToContain('5000');

    assertDatabaseHas('api_keys', ['name' => 'Webmail', 'rate_limit' => 5000]);
});

test('create command rejects an invalid rate limit', function (): void {
    $this->artisan('apikey:create', ['name' => 'Webmail', '--rate-limit' => 'lots'])
        ->assertExitCode(1);

    assertDatabaseCount('api_keys', 0);
});

// --- apikey:limit ---

test('limit command sets the rate limit', function (): void {
    $apiKey = ApiKey::generate('Webmail');

    $this->artisan('apikey:limit', ['id' => $apiKey->id, 'max' => '2500'])
        ->assertExitCode(0)
        ->expectsOutputToContain('2500');

    expect($apiKey->fresh()->rate_limit)->toBe(2500);
});

test('limit command restores the global default', function (): void {
    $apiKey = ApiKey::generate('Webmail', rateLimit: 2500);

    $this->artisan('apikey:limit', ['id' => $apiKey->id, 'max' => 'default'])
        ->assertExitCode(0)
        ->expectsOutputToContain('global rate limit');

    expect($apiKey->fresh()->rate_limit)->toBeNull();
});

test('limit command fails for unknown key', function (): void {
    $this->artisan('apikey:limit', ['id' => 999, 'max' => '10'])
        ->assertExitCode(1)
        ->expectsOutputToContain('not found');
});

// --- apikey:list ---

test('list command shows no keys message', function (): void {
    $this->artisan('apikey:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('No API keys found');
});

test('list command shows active keys', function (): void {
    ApiKey::generate('Key One');
    ApiKey::generate('Key Two');

    $this->artisan('apikey:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('Key One')
        ->expectsOutputToContain('Key Two');
});

test('list command hides revoked by default', function (): void {
    ApiKey::generate('Active Key');
    $revoked = ApiKey::generate('Revoked Key');
    $revoked->revoke();

    $this->artisan('apikey:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('Active Key')
        ->doesntExpectOutputToContain('Revoked Key');
});

test('list command with revoked option', function (): void {
    ApiKey::generate('Active Key');
    $revoked = ApiKey::generate('Revoked Key');
    $revoked->revoke();

    $this->artisan('apikey:list', ['--revoked' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Revoked Key')
        ->doesntExpectOutputToContain('Active Key');
});

test('list command with all option', function (): void {
    ApiKey::generate('Active Key');
    $revoked = ApiKey::generate('Revoked Key');
    $revoked->revoke();

    $this->artisan('apikey:list', ['--all' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Active Key')
        ->expectsOutputToContain('Revoked Key');
});

// --- apikey:revoke ---

test('revoke command revokes key', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    $this->artisan('apikey:revoke', ['id' => $apiKey->id])
        ->expectsConfirmation(
            "Are you sure you want to revoke the API key 'Test Key'?",
            'yes',
        )
        ->assertExitCode(0)
        ->expectsOutputToContain('has been revoked');

    $apiKey->refresh();
    expect($apiKey->revoked_at)->not->toBeNull();
});

test('revoke command cancel', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    $this->artisan('apikey:revoke', ['id' => $apiKey->id])
        ->expectsConfirmation(
            "Are you sure you want to revoke the API key 'Test Key'?",
            'no',
        )
        ->assertExitCode(0)
        ->expectsOutputToContain('cancelled');

    $apiKey->refresh();
    expect($apiKey->revoked_at)->toBeNull();
});

test('revoke command with nonexistent id', function (): void {
    $this->artisan('apikey:revoke', ['id' => 999])
        ->assertExitCode(1)
        ->expectsOutputToContain('not found');
});

test('revoke command with already revoked key', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    $apiKey->revoke();

    $this->artisan('apikey:revoke', ['id' => $apiKey->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('already revoked');
});
