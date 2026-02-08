<?php

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api_key' => \App\Http\Middleware\ValidateApiKey::class,
        ]);

        // Normalize double slashes in URL paths (e.g. //i/ -> /i/)
        $middleware->prepend(\App\Http\Middleware\NormalizeUrlSlashes::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
