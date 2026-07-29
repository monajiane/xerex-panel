<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DnsController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EdgeServerController;
use App\Http\Controllers\Api\FailoverGroupController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\IpListController;
use App\Http\Controllers\Api\OriginServerController;
use App\Http\Controllers\Api\ProxyRuleController;
use App\Http\Controllers\Api\RateLimitController;
use App\Http\Controllers\Api\SslController;
use App\Http\Controllers\Api\WafRuleController;
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
| Public billing (plan catalog is browsable without auth)
|--------------------------------------------------------------------------
*/
Route::prefix('billing')->group(function () {
    Route::get('plans', [BillingController::class, 'plans']);
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

    // DNS
    Route::prefix('dns')->group(function () {
        Route::get('zones',                       [DnsController::class, 'zones']);
        Route::post('zones',                      [DnsController::class, 'createZone']);
        Route::delete('zones/{zone}',             [DnsController::class, 'deleteZone']);
        Route::get('zones/{zone}/records',        [DnsController::class, 'records']);
        Route::post('zones/{zone}/records',       [DnsController::class, 'addRecord']);
        Route::put('zones/{zone}/records/{id}',   [DnsController::class, 'updateRecord']);
        Route::delete('zones/{zone}/records/{id}',[DnsController::class, 'deleteRecord']);
        Route::post('zones/{zone}/sync',          [DnsController::class, 'syncDomain']);
        Route::get('verify',                      [DnsController::class, 'verify']);
    });

    // SSL certificates
    Route::prefix('ssl')->group(function () {
        Route::get('/',                       [SslController::class, 'index']);
        Route::post('issue',                 [SslController::class, 'issue']);
        Route::post('{ssl}/renew',           [SslController::class, 'renew']);
        Route::delete('{ssl}',               [SslController::class, 'revoke']);
    });

    // Health checks
    Route::prefix('health-checks')->group(function () {
        Route::get('/',                          [HealthCheckController::class, 'index']);
        Route::get('stats',                      [HealthCheckController::class, 'stats']);
        Route::get('origin/{originServer}',      [HealthCheckController::class, 'origin']);
        Route::get('edge/{edgeServer}',          [HealthCheckController::class, 'edge']);
        Route::post('run',                       [HealthCheckController::class, 'runNow']);
    });

    // Failover groups
    Route::prefix('failover-groups')->group(function () {
        Route::get('/',                  [FailoverGroupController::class, 'index']);
        Route::post('/',                 [FailoverGroupController::class, 'store']);
        Route::get('{group}',            [FailoverGroupController::class, 'show']);
        Route::post('{group}/promote',   [FailoverGroupController::class, 'promote']);
        Route::post('{group}/reorder',   [FailoverGroupController::class, 'reorder']);
        Route::delete('{group}',         [FailoverGroupController::class, 'destroy']);
    });

    // Traffic analytics (reads from pre-aggregated traffic_rollups)
    Route::prefix('analytics')->group(function () {
        Route::get('series',      [AnalyticsController::class, 'series']);
        Route::get('summary',     [AnalyticsController::class, 'summary']);
        Route::get('top-domains', [AnalyticsController::class, 'topDomains']);
        Route::get('top-rules',   [AnalyticsController::class, 'topRules']);
        Route::post('rebuild',    [AnalyticsController::class, 'rebuild']);
    });

    // Billing (current user)
    Route::prefix('billing')->group(function () {
        Route::get('plans',                       [BillingController::class, 'plans']);
        Route::get('subscription',                [BillingController::class, 'showSubscription']);
        Route::post('subscription',               [BillingController::class, 'subscribe']);
        Route::post('subscription/cancel',        [BillingController::class, 'cancelSubscription']);
        Route::post('subscription/resume',        [BillingController::class, 'resumeSubscription']);
        Route::get('quotas',                      [BillingController::class, 'quotas']);
        Route::get('invoices',                    [BillingController::class, 'invoices']);
        Route::get('invoices/{invoice}',          [BillingController::class, 'showInvoice']);
        Route::post('invoices/{invoice}/pay',     [BillingController::class, 'payInvoice']);
        // Admin-only: re-seed the default plan set
        Route::post('plans/seed',                 [BillingController::class, 'seedPlans']);
    });

    // Security: WAF, IP lists, rate limits
    Route::prefix('security')->group(function () {
        // WAF rules
        Route::get('waf/rules',                       [WafRuleController::class, 'index']);
        Route::post('waf/rules',                      [WafRuleController::class, 'store']);
        Route::get('waf/rules/{wafRule}',             [WafRuleController::class, 'show']);
        Route::put('waf/rules/{wafRule}',             [WafRuleController::class, 'update']);
        Route::patch('waf/rules/{wafRule}',           [WafRuleController::class, 'update']);
        Route::delete('waf/rules/{wafRule}',          [WafRuleController::class, 'destroy']);
        Route::post('waf/rules/{wafRule}/toggle',     [WafRuleController::class, 'toggle']);
        Route::post('waf/test',                       [WafRuleController::class, 'test']);

        // IP allow / block lists
        Route::get('ip-lists',                        [IpListController::class, 'index']);
        Route::post('ip-lists',                       [IpListController::class, 'store']);
        Route::get('ip-lists/{ipList}',               [IpListController::class, 'show']);
        Route::put('ip-lists/{ipList}',               [IpListController::class, 'update']);
        Route::patch('ip-lists/{ipList}',             [IpListController::class, 'update']);
        Route::delete('ip-lists/{ipList}',            [IpListController::class, 'destroy']);
        Route::post('ip-lists/bulk',                  [IpListController::class, 'bulkImport']);
        Route::post('ip-lists/check',                 [IpListController::class, 'check']);

        // Rate-limit policies
        Route::get('rate-limits',                     [RateLimitController::class, 'index']);
        Route::post('rate-limits',                    [RateLimitController::class, 'store']);
        Route::get('rate-limits/{rateLimit}',         [RateLimitController::class, 'show']);
        Route::put('rate-limits/{rateLimit}',         [RateLimitController::class, 'update']);
        Route::patch('rate-limits/{rateLimit}',       [RateLimitController::class, 'update']);
        Route::delete('rate-limits/{rateLimit}',      [RateLimitController::class, 'destroy']);
        Route::post('rate-limits/{rateLimit}/toggle', [RateLimitController::class, 'toggle']);
        Route::get('rate-limits/{rateLimit}/inspect', [RateLimitController::class, 'inspect']);
        Route::post('rate-limits/{rateLimit}/reset',  [RateLimitController::class, 'reset']);
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
