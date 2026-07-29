<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block any web/API request unless the panel has been installed.
 *
 * The middleware is intentionally lightweight: a single file_exists() on
 * storage/installed.lock. We do NOT hit the database here, because the
 * whole point of this guard is to redirect to the installer when the
 * database itself is not configured yet.
 *
 *   - Web requests   → 302 to /install
 *   - API requests   → 503 JSON with the same message
 *   - /install/*     → bypass (the wizard itself sets the lock)
 *   - /up            → bypass (health check used by load balancers)
 *   - assets/js/css  → bypass (Vite-served bundles must keep loading)
 *
 * Register the alias in bootstrap/app.php:
 *   $middleware->alias(['install.guard' => EnsureInstalled::class]);
 *   $middleware->appendToGroup('web', EnsureInstalled::class);
 *   $middleware->appendToGroup('api', EnsureInstalled::class);
 */
class EnsureInstalled
{
    /**
     * Routes that are always reachable regardless of install state.
     * The web installer handles its own lock check on POST.
     */
    private const ALWAYS_OPEN = [
        'install',
        'install/*',
        'up',
        'livewire/*',
        '_debugbar/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Installer $installer */
        $installer = app(Installer::class);

        if ($installer->isInstalled()) {
            return $next($request);
        }

        // Allow static assets through so the wizard's CSS/JS still load.
        if ($this->isAsset($request)) {
            return $next($request);
        }

        // Allow install wizard and health endpoint.
        if ($this->isAlwaysOpen($request)) {
            return $next($request);
        }

        // API & JSON callers get a structured 503.
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'NOT_INSTALLED',
                'message' => 'Xerex Panel is not installed yet. Run `php artisan xerex:install` or visit /install.',
            ], 503);
        }

        // Browser users get redirected to the wizard.
        return redirect()->route('install.welcome');
    }

    /**
     * Is the request for a static asset (Vite bundle, image, etc.)?
     */
    private function isAsset(Request $request): bool
    {
        $path = $request->path();
        return (bool) preg_match('/\.(?:css|js|map|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot|otf)$/i', $path);
    }

    /**
     * Is the request path on the always-open list?
     */
    private function isAlwaysOpen(Request $request): bool
    {
        foreach (self::ALWAYS_OPEN as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }
        return false;
    }
}
