<?php

namespace App\Providers;

use App\Services\CertbotService;
use App\Services\Dns\PowerDNSService;
use App\Services\HealthCheckService;
use App\Services\NginxConfigGenerator;
use Illuminate\Support\ServiceProvider;

class XerexServiceProvider extends ServiceProvider
{
    public array $singletons = [
        NginxConfigGenerator::class => NginxConfigGenerator::class,
        PowerDNSService::class      => PowerDNSService::class,
        HealthCheckService::class   => HealthCheckService::class,
        CertbotService::class       => CertbotService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(PowerDNSService::class, fn () => PowerDNSService::make());
    }

    public function boot(): void
    {
        //
    }
}
