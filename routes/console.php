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
|   * xerex:ssl:renew                — daily @ 03:30         (Certbot renewals)
|   * xerex:health:check             — every minute           (HTTP probes)
|   * xerex:traffic:rollup           — every 5 minutes        (TrafficAggregator)
|   * xerex:traffic:prune            — daily @ 04:00          (delete old raw logs)
|   * xerex:edges:reap-stale         — hourly                 (mark offline edges)
|   * xerex:billing:roll-subscriptions — hourly               (period boundaries, expirations)
|   * xerex:billing:generate-invoices — daily @ 00:30         (monthly invoice issuance)
|   * xerex:security:prune-expiry    — hourly                 (remove expired IP entries)
*/

Schedule::command('xerex:ssl:renew')->dailyAt('03:30');
Schedule::command('xerex:health:check')->everyMinute();
Schedule::command('xerex:traffic:rollup')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('xerex:traffic:rollup --full-day')->dailyAt('00:05');
Schedule::command('xerex:traffic:prune')->dailyAt('04:00');
Schedule::command('xerex:edges:reap-stale')->hourly();
Schedule::command('xerex:billing:roll-subscriptions')->hourly()->withoutOverlapping();
Schedule::command('xerex:billing:generate-invoices')->dailyAt('00:30')->withoutOverlapping();

// Security maintenance
Schedule::command('xerex:security:prune-expiry')->hourly()->withoutOverlapping();
