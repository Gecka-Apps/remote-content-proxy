<?php

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| Scheduled tasks for the Remote Content Proxy.
|
*/

Schedule::command('proxy:cache:maintain')
    ->dailyAt('3:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/proxy-cache-maintenance.log'));
