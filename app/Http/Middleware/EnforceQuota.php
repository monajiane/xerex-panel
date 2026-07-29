<?php

namespace App\Http\Middleware;

use App\Services\QuotaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce a quota on a write route.
 *
 *   Route::post('/domains', ...)->middleware('enforce.quota:domains');
 *
 * The metric name is read from the route parameter (or string argument).
 * Quota check is performed BEFORE the controller runs; a 402 is returned
 * with a JSON body explaining the denial.
 *
 * Soft limits: if a limit is marked is_soft=true we still let the action
 * through but include a `X-Quota-Soft-Warning` header so the UI can warn.
 */
class EnforceQuota
{
    public function __construct(private readonly QuotaService $quotas) {}

    public function handle(Request $request, Closure $next, string $metric = '', int $delta = 1): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // If the caller didn't specify a metric, look for a `metric` query param.
        $metric = $metric !== '' ? $metric : (string) $request->query('metric', '');
        if ($metric === '') {
            // No metric -> nothing to enforce.
            return $next($request);
        }

        $result = $this->quotas->check($user, $metric, $delta);

        if (! $result->allowed) {
            return response()->json([
                'error'   => 'quota_exceeded',
                'metric'  => $result->metric,
                'used'    => $result->used,
                'limit'   => $result->limit,
                'plan'    => $result->planName,
                'message' => "You have reached the {$metric} limit on the {$result->planName} plan.",
            ], 402);
        }

        /** @var Response $response */
        $response = $next($request);
        if ($result->isSoft()) {
            $response->headers->set('X-Quota-Soft-Warning', "approaching {$metric} limit");
        }
        return $response;
    }
}
