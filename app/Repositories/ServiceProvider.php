<?php

namespace App\Repositories;

use App\Repositories\Contracts\DomainRepository;
use App\Repositories\Contracts\EdgeServerRepository;
use App\Repositories\Contracts\OriginServerRepository;
use App\Repositories\Contracts\ProxyRuleRepository;
use App\Repositories\Eloquent\EloquentDomainRepository;
use App\Repositories\Eloquent\EloquentEdgeServerRepository;
use App\Repositories\Eloquent\EloquentOriginServerRepository;
use App\Repositories\Eloquent\EloquentProxyRuleRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        EdgeServerRepository::class   => EloquentEdgeServerRepository::class,
        OriginServerRepository::class => EloquentOriginServerRepository::class,
        DomainRepository::class       => EloquentDomainRepository::class,
        ProxyRuleRepository::class    => EloquentProxyRuleRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }
}
