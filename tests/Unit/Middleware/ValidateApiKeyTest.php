<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateApiKey;
use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->middleware = new ValidateApiKey();
});

function passThrough(ValidateApiKey $middleware, Request $request): Response
{
    return $middleware->handle($request, fn() => new Response('OK', 200));
}

// --- API key disabled ---

test('passes through when api key not required', function (): void {
    Config::set('proxy.require_api_key', false);

    $request = Request::create('/test', 'GET');
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(200);
});

// --- Missing API key ---

test('returns 401 when no api key provided', function (): void {
    Config::set('proxy.require_api_key', true);

    $request = Request::create('/test', 'GET');
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(401);
    expect($response->getContent())->toContain('API key required');
});

// --- Invalid API key ---

test('returns 401 for invalid api key', function (): void {
    Config::set('proxy.require_api_key', true);

    $request = Request::create('/test', 'GET');
    $request->headers->set('X-API-Key', 'invalid-key');
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(401);
    expect($response->getContent())->toContain('Invalid API key');
});

// --- Valid key via X-API-Key header ---

test('passes with valid x api key header', function (): void {
    Config::set('proxy.require_api_key', true);
    $apiKey = ApiKey::generate('Test');

    $request = Request::create('/test', 'GET');
    $request->headers->set('X-API-Key', $apiKey->getPlainKey());
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(200);
});

// --- Valid key via Bearer token ---

test('passes with valid bearer token', function (): void {
    Config::set('proxy.require_api_key', true);
    $apiKey = ApiKey::generate('Test');

    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $apiKey->getPlainKey());
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(200);
});

// --- Valid key via query parameter ---

test('passes with valid query parameter', function (): void {
    Config::set('proxy.require_api_key', true);
    $apiKey = ApiKey::generate('Test');

    $request = Request::create('/test?api_key=' . $apiKey->getPlainKey(), 'GET');
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(200);
});

// --- Revoked key ---

test('returns 401 for revoked key', function (): void {
    Config::set('proxy.require_api_key', true);
    $apiKey = ApiKey::generate('Test');
    $apiKey->revoke();

    $request = Request::create('/test', 'GET');
    $request->headers->set('X-API-Key', $apiKey->getPlainKey());
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(401);
});

// --- touchLastUsed ---

test('updates last used on valid key', function (): void {
    Config::set('proxy.require_api_key', true);
    $apiKey = ApiKey::generate('Test');
    expect($apiKey->last_used_at)->toBeNull();

    $request = Request::create('/test', 'GET');
    $request->headers->set('X-API-Key', $apiKey->getPlainKey());
    passThrough($this->middleware, $request);

    $apiKey->refresh();
    expect($apiKey->last_used_at)->not->toBeNull();
});

// --- Priority: Bearer > X-API-Key > query ---

test('bearer token takes priority over header', function (): void {
    Config::set('proxy.require_api_key', true);
    $validKey = ApiKey::generate('Valid');

    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $validKey->getPlainKey());
    $request->headers->set('X-API-Key', 'invalid-key');
    $response = passThrough($this->middleware, $request);

    expect($response->getStatusCode())->toBe(200);
});
