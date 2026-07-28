<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EdgeServerController;
use App\Http\Controllers\Api\OriginServerController;
use App\Http\Controllers\Api\ProxyRuleController;
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
