<?php

namespace App\Providers;

use App\Services\BillingService;
use App\Services\CertbotService;
use App\Services\Dns\PowerDNSService;
use App\Services\FailoverGroupService;
use App\Services\HealthCheckService;
use App\Services\NginxConfigGenerator;
use App\Services\QuotaService;
use App\Services\Security\IpListService;
use App\Services\Security\RateLimiter;
use App\Services\Security\WafEngine;
use App\Services\TrafficAggregator;
use App\Services\UsageMeter;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class XerexServiceProvider extends ServiceProvider
{
    /**
     * Bind every Xerex service as a singleton so the application gets the
     * same shared instance across requests, queue jobs, and console commands.
     */
    public array $singletons = [
        NginxConfigGenerator::class  => NginxConfigGenerator::class,
        PowerDNSService::class       => PowerDNSService::class,
        HealthCheckService::class    => HealthCheckService::class,
        CertbotService::class        => CertbotService::class,
        FailoverGroupService::class  => FailoverGroupService::class,
        TrafficAggregator::class     => TrafficAggregator::class,
        QuotaService::class          => QuotaService::class,
        BillingService::class        => BillingService::class,
        UsageMeter::class            => UsageMeter::class,
        WafEngine::class             => WafEngine::class,
        IpListService::class         => IpListService::class,
        RateLimiter::class           => RateLimiter::class,
    ];

    public function register(): void
    {
        // PowerDNSService requires runtime config; we resolve it via the make()
        // factory so it picks up env() at boot time.
        $this->app->singleton(PowerDNSService::class, fn () => PowerDNSService::make());

        // HealthCheckService also reads config() for timeouts/thresholds.
        $this->app->singleton(HealthCheckService::class, fn () => HealthCheckService::make());

        // Security services honour a configured TTL (in seconds) so heavy
        // request volume doesn't re-query the rule tables on every hit.
        $this->app->singleton(WafEngine::class, fn () => new WafEngine(
            (int) config('xerex.waf.cache_ttl', 60),
        ));
        $this->app->singleton(IpListService::class, fn () => new IpListService(
            (int) config('xerex.iplist.cache_ttl', 60),
        ));
        $this->app->singleton(RateLimiter::class, fn () => new RateLimiter(
            (int) config('xerex.ratelimit.cache_ttl', 60),
        ));
    }

    public function boot(): void
    {
        // Register the EnforceQuota route middleware under the alias
        // "enforce.quota" so route definitions can use it.
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('enforce.quota',  \App\Http\Middleware\EnforceQuota::class);
        $router->aliasMiddleware('waf',            \App\Http\Middleware\WafEvaluate::class);
        $router->aliasMiddleware('ip.list',        \App\Http\Middleware\IpListCheck::class);
        $router->aliasMiddleware('rate.limit',     \App\Http\Middleware\EnforceRateLimit::class);
    }
}

