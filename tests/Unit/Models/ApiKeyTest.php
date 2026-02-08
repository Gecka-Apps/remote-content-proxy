<?php

declare(strict_types=1);

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- generate ---

test('generate creates api key with name', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    expect($apiKey->name)->toBe('Test Key');
    expect($apiKey->key)->not->toBeEmpty();
    expect($apiKey->revoked_at)->toBeNull();
    expect($apiKey->last_used_at)->toBeNull();
});

test('generate stores sha256 hash', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    $plainKey = $apiKey->getPlainKey();

    // Stored key is SHA-256 hash of the plain key
    expect(strlen($apiKey->key))->toBe(64);
    expect($apiKey->key)->toMatch('/^[a-f0-9]{64}$/');
    expect($apiKey->key)->toBe(hash('sha256', $plainKey));
});

test('generate returns plain key', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    $plainKey = $apiKey->getPlainKey();

    expect($plainKey)->not->toBeNull();
    expect(strlen($plainKey))->toBe(64);
    expect($plainKey)->toMatch('/^[a-f0-9]{64}$/');
    // Plain key differs from stored hash
    expect($apiKey->key)->not->toBe($plainKey);
});

test('plain key not available after fetch', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    $fetched = ApiKey::find($apiKey->id);

    expect($fetched->getPlainKey())->toBeNull();
});

test('generate creates unique keys', function (): void {
    $key1 = ApiKey::generate('Key 1');
    $key2 = ApiKey::generate('Key 2');

    expect($key2->key)->not->toBe($key1->key);
    expect($key2->getPlainKey())->not->toBe($key1->getPlainKey());
});

// --- findByKey ---

test('find by key returns active key', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    $plainKey = $apiKey->getPlainKey();

    $found = ApiKey::findByKey($plainKey);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($apiKey->id);
});

test('find by key returns null for unknown key', function (): void {
    expect(ApiKey::findByKey('nonexistent-key'))->toBeNull();
});

test('find by key returns null for revoked key', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    $plainKey = $apiKey->getPlainKey();
    $apiKey->revoke();

    expect(ApiKey::findByKey($plainKey))->toBeNull();
});

test('find by key does not match hash directly', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    // Querying with the hash itself should NOT work
    expect(ApiKey::findByKey($apiKey->key))->toBeNull();
});

// --- isValid ---

test('is valid returns true for active key', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    expect($apiKey->isValid())->toBeTrue();
});

test('is valid returns false for revoked key', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    $apiKey->revoke();

    $apiKey->refresh();
    expect($apiKey->isValid())->toBeFalse();
});

// --- revoke ---

test('revoke sets revoked at', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    $apiKey->revoke();

    $apiKey->refresh();
    expect($apiKey->revoked_at)->not->toBeNull();
});

// --- touchLastUsed ---

test('touch last used updates timestamp', function (): void {
    $apiKey = ApiKey::generate('Test Key');
    expect($apiKey->last_used_at)->toBeNull();

    $apiKey->touchLastUsed();

    $apiKey->refresh();
    expect($apiKey->last_used_at)->not->toBeNull();
});

// --- scopes ---

test('scope active filters revoked keys', function (): void {
    $active = ApiKey::generate('Active');
    $revoked = ApiKey::generate('Revoked');
    $revoked->revoke();

    $activeKeys = ApiKey::active()->get();

    expect($activeKeys)->toHaveCount(1);
    expect($activeKeys->first()->id)->toBe($active->id);
});

test('scope revoked filters active keys', function (): void {
    ApiKey::generate('Active');
    $revoked = ApiKey::generate('Revoked');
    $revoked->revoke();

    $revokedKeys = ApiKey::revoked()->get();

    expect($revokedKeys)->toHaveCount(1);
    expect($revokedKeys->first()->id)->toBe($revoked->id);
});

test('touch last used throttled within 5 minutes', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    // First touch should update
    $apiKey->touchLastUsed();
    $apiKey->refresh();
    $firstTouch = $apiKey->last_used_at;
    expect($firstTouch)->not->toBeNull();

    // Simulate calling again immediately — should NOT update
    $apiKey->touchLastUsed();
    $apiKey->refresh();
    expect($apiKey->last_used_at->toDateTimeString())->toEqual($firstTouch->toDateTimeString());
});

test('touch last used updates after threshold', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    // Set last_used_at to 10 minutes ago
    $apiKey->update(['last_used_at' => now()->subMinutes(10)]);
    $apiKey->refresh();
    $oldTimestamp = $apiKey->last_used_at;

    // Should update because more than 5 minutes have passed
    $apiKey->touchLastUsed();
    $apiKey->refresh();
    expect($apiKey->last_used_at)->toBeGreaterThan($oldTimestamp);
});

// --- hidden attribute ---

test('key is hidden in serialization', function (): void {
    $apiKey = ApiKey::generate('Test Key');

    $array = $apiKey->toArray();

    expect($array)->not->toHaveKey('key');
});
