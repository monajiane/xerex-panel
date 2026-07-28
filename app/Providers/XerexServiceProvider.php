<?php

namespace App\Providers;

use App\Services\CertbotService;
use App\Services\Dns\PowerDNSService;
use App\Services\FailoverGroupService;
use App\Services\HealthCheckService;
use App\Services\NginxConfigGenerator;
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
    ];

    public function register(): void
    {
        // PowerDNSService requires runtime config; we resolve it via the make()
        // factory so it picks up env() at boot time.
        $this->app->singleton(PowerDNSService::class, fn () => PowerDNSService::make());

        // HealthCheckService also reads config() for timeouts/thresholds.
        $this->app->singleton(HealthCheckService::class, fn () => HealthCheckService::make());
    }

    public function boot(): void
    {
        //
    }
}
