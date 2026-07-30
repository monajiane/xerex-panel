<?php

use App\Http\Middleware\EnsureInstalled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        // ============================================================
        // Install guard
        // ------------------------------------------------------------
        // The EnsureInstalled middleware is appended to BOTH the web
        // and api groups. It checks for storage/installed.lock and
        // redirects to the installer when the lock is missing.
        //
        // The middleware is smart enough to let the install wizard
        // itself through (it whitelists /install/* and static assets)
        // so we don't need a separate "no-guard" route group.
        // ============================================================
        $middleware->appendToGroup('web', EnsureInstalled::class);
        $middleware->appendToGroup('api', EnsureInstalled::class);

        $middleware->alias([
            'role'                 => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'           => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission'   => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'edge.auth'            => \App\Http\Middleware\AuthenticateEdgeAgent::class,
            'audit'                => \App\Http\Middleware\AuditLog::class,
            'install.guard'        => EnsureInstalled::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            if ($request->is('api/*')) {
                return true;
            }
            return $request->expectsJson();
        });
    })
    ->withProviders([
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\XerexServiceProvider::class,
        App\Repositories\RepositoryServiceProvider::class,
    ])
    ->create();
