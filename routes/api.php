<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\DnsController;
use App\Http\Controllers\Api\EdgeServerController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\OriginServerController;
use App\Http\Controllers\Api\ProxyRuleController;
use App\Http\Controllers\Api\SslController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| All routes here are prefixed with /api by RouteServiceProvider.
| Authentication: Sanctum bearer tokens.
*/

/*
|--------------------------------------------------------------------------
| Public authentication endpoints
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login',    [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

/*
|--------------------------------------------------------------------------
| Authenticated user endpoints
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me',             [AuthController::class, 'me']);
        Route::post('logout',        [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('stats',           [DashboardController::class, 'stats']);
        Route::get('traffic-series',  [DashboardController::class, 'trafficSeries']);
        Route::get('health-checks',   [DashboardController::class, 'recentHealthChecks']);
    });

    // Edge servers (admin only for write)
    Route::apiResource('edge-servers', EdgeServerController::class);
    Route::post('edge-servers/{edgeServer}/test',  [EdgeServerController::class, 'testConnection']);
    Route::post('edge-servers/{edgeServer}/rotate-token', [EdgeServerController::class, 'rotateToken']);

    // Origin servers
    Route::apiResource('origin-servers', OriginServerController::class);
    Route::post('origin-servers/{originServer}/test', [OriginServerController::class, 'test']);

    // Domains
    Route::apiResource('domains', DomainController::class);

    // Proxy rules
    Route::apiResource('proxy-rules', ProxyRuleController::class);
    Route::post('proxy-rules/{proxyRule}/toggle', [ProxyRuleController::class, 'toggle']);

    // DNS zones & records (PowerDNS integration)
    Route::prefix('dns')->group(function () {
        Route::get('zones',                        [DnsController::class, 'indexZones']);
        Route::post('zones',                       [DnsController::class, 'createZone']);
        Route::get('zones/{zone}',                 [DnsController::class, 'showZone']);
        Route::delete('zones/{zone}',              [DnsController::class, 'destroyZone']);
        Route::get('zones/{zone}/records',         [DnsController::class, 'listRecords']);
        Route::post('zones/{zone}/records',        [DnsController::class, 'addRecord']);
        Route::delete('records/{record}',          [DnsController::class, 'deleteRecord']);
        Route::post('zones/{zone}/sync-domain',    [DnsController::class, 'syncFromDomain']);
        Route::get('verify',                       [DnsController::class, 'verify']);
    });

    // SSL certificates (Certbot / Let's Encrypt)
    Route::prefix('ssl')->group(function () {
        Route::get('certificates',                 [SslController::class, 'index']);
        Route::post('certificates',                [SslController::class, 'issue']);
        Route::get('certificates/{certificate}',   [SslController::class, 'show']);
        Route::post('certificates/{certificate}/renew',  [SslController::class, 'renew']);
        Route::delete('certificates/{certificate}',[SslController::class, 'revoke']);
        Route::get('expiring',                     [SslController::class, 'expiring']);
    });

    // Health checks & auto-failover
    Route::prefix('health-checks')->group(function () {
        Route::get('/',                            [HealthCheckController::class, 'index']);
        Route::get('stats',                        [HealthCheckController::class, 'stats']);
        Route::post('run',                         [HealthCheckController::class, 'runNow']);

        Route::get('origins/{originServer}',                  [HealthCheckController::class, 'originHistory']);
        Route::post('origins/{originServer}/probe',          [HealthCheckController::class, 'probeOrigin']);
        Route::post('origins/{originServer}/reactivate',     [HealthCheckController::class, 'reactivateOrigin']);

        Route::post('edges/{edgeServer}/probe',              [HealthCheckController::class, 'probeEdge']);
    });
});

/*
|--------------------------------------------------------------------------
| Edge Agent endpoints (separate auth: edge.auth)
|--------------------------------------------------------------------------
*/
Route::middleware('edge.auth')->prefix('agent')->group(function () {
    Route::get('config',     [AgentController::class, 'config']);
    Route::post('telemetry', [AgentController::class, 'telemetry']);
    Route::post('traffic',   [AgentController::class, 'traffic']);
    Route::post('health',    [AgentController::class, 'health']);
});
