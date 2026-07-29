<?php

namespace App\Http\Middleware;

use App\Models\WafRule;
use App\Services\Security\WafEngine;
use App\Services\Security\WafRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run the WAF rule set against the incoming request.
 *
 *   Route::middleware('waf')->group(function () { ... });
 *
 * The middleware can be configured to:
 *   - bypass specific URIs (suffix ?exclude=api/health)
 *   - run in "audit-only" mode (always next(), log matches)
 *
 * Blocking / challenging actions short-circuit with 403 / 412.
 */
class WafEvaluate
{
    public function __construct(private readonly WafEngine $waf) {}

    public function handle(Request $request, Closure $next, string $mode = 'enforce'): Response
    {
        $wafRequest = $this->buildRequest($request);
        [$scopeType, $scopeId] = $this->resolveScope($request);

        $result = $this->waf->evaluate($wafRequest, $scopeType, $scopeId);

        if (!$result->matched) {
            return $next($request);
        }

        // Audit mode: log + continue.
        if ($mode === 'audit' || $result->action === WafRule::ACTION_LOG) {
            Log::info('waf.audit', [
                'rule'   => optional($result->rule)->slug,
                'action' => $result->action,
                'path'   => $request->path(),
                'ip'     => $request->ip(),
            ]);
            if ($result->action === WafRule::ACTION_LOG) {
                return $next($request);
            }
        }

        if ($result->isBlocking()) {
            return response()->json([
                'error'    => 'request_blocked',
                'rule'     => optional($result->rule)->slug,
                'action'   => $result->action,
                'evidence' => $result->evidence,
            ], $result->action === WafRule::ACTION_CHALLENGE ? 412 : 403);
        }

        return $next($request);
    }

    private function buildRequest(Request $request): WafRequest
    {
        return new WafRequest(
            method:    $request->getMethod(),
            uri:        $request->getRequestUri(),
            query:      $request->getQueryString() ?? '',
            body:       (string) $request->getContent(),
            userAgent:  (string) $request->userAgent(),
            headers:    $request->headers->all(),
            clientIp:   $request->ip(),
        );
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
        if (!in_array($scope, ['domain', 'edge'], true)) {
            return [null, null];
        }
        $id = $request->route($param);
        return [$scope, is_numeric($id) ? (int) $id : null];
    }
}
