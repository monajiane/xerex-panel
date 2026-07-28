<?php

namespace App\Providers;

use App\Models\Domain;
use App\Models\EdgeServer;
use App\Models\OriginServer;
use App\Models\ProxyRule;
use App\Models\SslCertificate;
use App\Observers\EdgeServerObserver;
use App\Observers\OriginServerObserver;
use App\Observers\ProxyRuleObserver;
use App\Observers\SslCertificateObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Prevent lazy loading in production for performance
        Model::preventLazyLoading(! app()->isLocal());
        Model::shouldBeStrict(! app()->isLocal());

        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Observers
        ProxyRule::observe(ProxyRuleObserver::class);
        OriginServer::observe(OriginServerObserver::class);
        EdgeServer::observe(EdgeServerObserver::class);
        SslCertificate::observe(SslCertificateObserver::class);
    }
}
