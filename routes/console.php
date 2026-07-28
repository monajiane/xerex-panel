<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
*/
Schedule::command('xerex:ssl:renew')->dailyAt('03:30');
Schedule::command('xerex:health:check')->everyMinute();
Schedule::command('xerex:traffic:purge')->daily();
Schedule::command('xerex:edges:reap-stale')->hourly();
