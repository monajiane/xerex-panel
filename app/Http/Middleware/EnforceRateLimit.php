<?php

namespace App\Http\Middleware;

use App\Models\RateLimit;
use App\Services\Security\RateLimitRequest;
use App\Services\Security\RateLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply active rate-limit policies to a route group.
 *
 *   Route::middleware('rate.limit')->group(function () { ... });
 *
 * The middleware composes all matching policies: any blocking policy
 * short-circuits the chain with 429 Too Many Requests (or 412 when
 * the policy action is "challenge"). The 429 response carries
 * Retry-After + X-RateLimit-* headers so clients can back off.
 */
class EnforceRateLimit
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rlRequest = new RateLimitRequest(
            ip:     $request->ip(),
            userId: optional($request->user())->id,
            path:   $request->path(),
            domain: $request->getHost(),
            method: $request->getMethod(),
        );

        [$scopeType, $scopeId] = $this->resolveScope($request);
        $result = $this->limiter->check($rlRequest, $scopeType, $scopeId);

        if (!$result->allowed) {
            $status = $result->action === RateLimit::ACTION_CHALLENGE ? 412 : 429;
            return response()->json([
                'error'       => 'rate_limited',
                'policy'      => optional($result->policy)->slug,
                'action'      => $result->action,
                'limit'       => $result->limit,
                'retry_after' => $result->retryAfter,
            ], $status)
                ->header('Retry-After', (string) max(1, $result->retryAfter))
                ->header('X-RateLimit-Limit', (string) $result->limit)
                ->header('X-RateLimit-Remaining', '0');
        }

        /** @var Response $response */
        $response = $next($request);
        if ($result->policy) {
            $remaining = max(0, $result->limit - $result->current);
            $response->headers->set('X-RateLimit-Limit', (string) $result->limit);
            $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        }
        return $response;
    }

    /**
     * @return array{0:?string,1:?int}
     */
    private function resolveScope(Request $request): array
    {
        $name = optional($request->route())->getName();
        if (!is_string($name)) {
            return [null, null];
        }
        $parts = explode('.', $name);
        $scope = $parts[1] ?? null;
        $param = $parts[2] ?? null;
        if (!in_array($scope, ['domain', 'edge', 'user'], true)) {
            return [null, null];
        }
        $id = $request->route($param) ?? optional($request->user())->id;
        return [$scope, is_numeric($id) ? (int) $id : null];
    }
}
