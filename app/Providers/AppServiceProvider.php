<?php

namespace App\Providers;

use App\Models\ProxyRule;
use App\Observers\ProxyRuleObserver;
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
    }
}
