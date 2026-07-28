<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records an audit log entry for sensitive API actions.
 * The actual activity log entry is created via Spatie's activitylog
 * inside the controllers / services.
 */
class AuditLog
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only audit mutating verbs on /api
        if (! $request->is('api/*')) {
            return $response;
        }
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        activity('api')
            ->causedBy($request->user())
            ->withProperties([
                'method'   => $request->method(),
                'path'     => $request->path(),
                'ip'       => $request->ip(),
                'user_agent'=> $request->userAgent(),
                'status'   => $response->getStatusCode(),
            ])
            ->log('api_request');

        return $response;
    }
}
