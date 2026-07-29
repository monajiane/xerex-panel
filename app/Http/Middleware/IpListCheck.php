<?php

namespace App\Http\Middleware;

use App\Services\Security\IpListService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drop requests whose client IP is on a global/scope-matched block entry.
 *
 *   Route::middleware('ip.list')->group(function () { ... });
 *
 * Scope (domain/edge) is inferred from the route name when the
 * segment after the second "." is "domain" or "edge", e.g.
 * `security.domains.audit` → scope_type=domain, scope_id=$domain->id.
 * For unscoped routes, only global entries are considered.
 */
class IpListCheck
{
    public function __construct(private readonly IpListService $ipLists) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $this->resolveIp($request);
        if ($ip === null) {
            return $next($request);
        }

        [$scopeType, $scopeId] = $this->resolveScope($request);

        $block = $this->ipLists->isBlocked($ip, $scopeType, $scopeId);
        if ($block !== null) {
            Log::warning('ip.blocked', [
                'ip'         => $ip,
                'reason'     => $block->reason,
                'cidr'       => $block->cidr,
                'source'     => $block->source,
                'scope_type' => $scopeType,
                'scope_id'   => $scopeId,
                'path'       => $request->path(),
            ]);
            return response()->json([
                'error'  => 'ip_blocked',
                'reason' => $block->reason,
            ], 403);
        }
        return $next($request);
    }

    /**
     * Best-effort client IP, trusting the standard X-Forwarded-For when
     * the application sits behind the edge proxy.
     */
    private function resolveIp(Request $request): ?string
    {
        return $request->ip() ?: ($request->server('REMOTE_ADDR') ?: null);
    }

    /**
     * Extract (scope_type, scope_id) from the route name when possible.
     *
     * @return array{0:?string,1:?int}
     */
    private function resolveScope(Request $request): array
    {
        $name = optional($request->route())->getName();
        if (!is_string($name)) {
            return [null, null];
        }
        $parts = explode('.', $name);

        // Convention: "<area>.<scope>.<resource>.<action>"
        $scope = $parts[1] ?? null;
        $param = $parts[2] ?? null;

        if (!in_array($scope, ['domain', 'edge'], true)) {
            return [null, null];
        }
        $id = $request->route($param);
        return [$scope, is_numeric($id) ? (int) $id : null];
    }
}
