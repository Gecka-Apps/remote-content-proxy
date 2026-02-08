<?php

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RemoteContentProxyController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Remote Content Proxy — Base64 encoded URL mode only
| URL format: /i/{base64EncodedUrl}?api_key=xxx
|
| Proxies remote content: images, CSS, fonts, videos
| Uses URL-safe Base64: + → -, / → _, no padding
|
*/

Route::get('/', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    return response()->json([
        'name' => 'Remote Content Proxy',
        'version' => $composer['version'] ?? 'unknown',
        'status' => 'ok',
    ]);
});

Route::middleware(['throttle:proxy', 'api_key'])->group(function () {
    Route::get('/i/{base64Url}', [RemoteContentProxyController::class, 'proxy'])
        ->where('base64Url', '[A-Za-z0-9_-]+');
});
