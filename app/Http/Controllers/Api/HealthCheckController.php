<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EdgeServer;
use App\Models\HealthCheck;
use App\Models\OriginServer;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * HealthCheckController - REST endpoints that drive the master-side
 * health-check workflow. Edge agents use AgentController@health for
 * edge-pushed reports; this controller is for the admin UI / dashboard.
 */
class HealthCheckController extends Controller
{
    public function __construct(protected HealthCheckService $service) {}

    /**
     * GET /api/health-checks
     * List recent checks (paged). Supports filtering by checkable type/id,
     * status, and date range.
     */
    public function index(Request $request): JsonResponse
    {
        $query = HealthCheck::query()->latest('checked_at');

        if ($type = $request->string('checkable_type')->toString()) {
            $query->where('checkable_type', $type);
        }
        if ($id = $request->integer('checkable_id')) {
            $query->where('checkable_id', $id);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($type = $request->string('check_type')->toString()) {
            $query->where('check_type', $type);
        }
        if ($since = $request->string('since')->toString()) {
            $query->where('checked_at', '>=', Carbon::parse($since));
        }

        $checks = $query->paginate(min(100, (int) $request->integer('per_page', 25)));

        return response()->json([
            'data' => $checks->items(),
            'meta' => [
                'total'        => $checks->total(),
                'per_page'     => $checks->perPage(),
                'current_page' => $checks->currentPage(),
                'last_page'    => $checks->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/health-checks/stats
     * Aggregate statistics: up/down counts, success rate, avg latency,
     * currently-disabled origins.
     */
    public function stats(Request $request): JsonResponse
    {
        $since = Carbon::parse($request->string('since', now()->subDay())->toString() ?: now()->subDay());

        $base = HealthCheck::query()->where('checked_at', '>=', $since);
        $total = (clone $base)->count();
        $up = (clone $base)->where('status', HealthCheck::STATUS_UP)->count();
        $down = (clone $base)->where('status', HealthCheck::STATUS_DOWN)->count();
        $degraded = (clone $base)->where('status', HealthCheck::STATUS_DEGRADED)->count();
        $timeout = (clone $base)->where('status', HealthCheck::STATUS_TIMEOUT)->count();

        $avgLatency = (clone $base)->whereNotNull('latency_ms')->avg('latency_ms');
        $p95Latency = (clone $base)
            ->whereNotNull('latency_ms')
            ->orderByDesc('latency_ms')
            ->limit((int) ceil($total * 0.05))
            ->min('latency_ms');

        return response()->json([
            'window' => [
                'from' => $since->toIso8601String(),
                'to'   => now()->toIso8601String(),
            ],
            'totals' => [
                'checks'   => $total,
                'up'       => $up,
                'down'     => $down,
                'degraded' => $degraded,
                'timeout'  => $timeout,
                'success_rate' => $total > 0 ? round($up / $total * 100, 2) : null,
            ],
            'latency_ms' => [
                'avg' => $avgLatency ? round($avgLatency, 2) : null,
                'p95' => $p95Latency ? round((float) $p95Latency, 2) : null,
            ],
            'origins' => [
                'total'    => OriginServer::count(),
                'active'   => OriginServer::where('is_active', true)->count(),
                'disabled' => OriginServer::where('is_active', false)->count(),
                'healthy'  => OriginServer::where('health_status', OriginServer::HEALTH_UP)->count(),
                'down'     => OriginServer::where('health_status', OriginServer::HEALTH_DOWN)->count(),
                'unknown'  => OriginServer::where('health_status', OriginServer::HEALTH_UNKNOWN)->count(),
            ],
            'edges' => [
                'total'    => EdgeServer::count(),
                'online'   => EdgeServer::where('status', EdgeServer::STATUS_ONLINE)->count(),
                'offline'  => EdgeServer::where('status', EdgeServer::STATUS_OFFLINE)->count(),
                'degraded' => EdgeServer::where('status', EdgeServer::STATUS_DEGRADED)->count(),
            ],
        ]);
    }

    /**
     * GET /api/health-checks/origins/{originServer}
     * Recent health-check history for a single origin.
     */
    public function originHistory(OriginServer $originServer, Request $request): JsonResponse
    {
        $limit = min(500, $request->integer('limit', 50));

        $history = $originServer->healthChecks()
            ->latest('checked_at')
            ->limit($limit)
            ->get();

        $uptime = null;
        $total = $history->count();
        if ($total > 0) {
            $uptime = round($history->where('status', HealthCheck::STATUS_UP)->count() / $total * 100, 2);
        }

        return response()->json([
            'origin' => [
                'id'              => $originServer->id,
                'name'            => $originServer->name,
                'health_status'   => $originServer->health_status,
                'is_active'       => $originServer->is_active,
                'consecutive_failures' => $originServer->consecutive_failures,
                'last_health_check_at' => $originServer->last_health_check_at?->toIso8601String(),
            ],
            'uptime_pct' => $uptime,
            'history'    => $history,
        ]);
    }

    /**
     * POST /api/health-checks/run
     * Trigger an immediate scheduled check pass (operator action).
     */
    public function runNow(Request $request): JsonResponse
    {
        $request->validate([
            'target' => ['nullable', 'string', 'in:all,origins,edges'],
        ]);

        $stats = $this->service->runScheduledChecks();

        return response()->json([
            'ok'    => true,
            'ran'   => $request->string('target', 'all')->toString(),
            'stats' => $stats,
        ]);
    }

    /**
     * POST /api/health-checks/origins/{originServer}/probe
     * Run an immediate probe against a single origin (regardless of schedule).
     */
    public function probeOrigin(OriginServer $originServer): JsonResponse
    {
        $check = $this->service->checkOrigin($originServer);

        return response()->json([
            'ok'    => true,
            'check' => $check,
            'origin' => [
                'id'              => $originServer->id,
                'name'            => $originServer->name,
                'health_status'   => $originServer->health_status,
                'is_active'       => $originServer->is_active,
                'consecutive_failures' => $originServer->consecutive_failures,
            ],
        ]);
    }

    /**
     * POST /api/health-checks/edges/{edgeServer}/probe
     * Run an immediate probe against a single edge.
     */
    public function probeEdge(EdgeServer $edgeServer): JsonResponse
    {
        $check = $this->service->checkEdge($edgeServer);

        return response()->json([
            'ok'    => true,
            'check' => $check,
            'edge'  => [
                'id'     => $edgeServer->id,
                'name'   => $edgeServer->name,
                'status' => $edgeServer->status,
            ],
        ]);
    }

    /**
     * POST /api/health-checks/origins/{originServer}/reactivate
     * Manually re-enable an origin that was auto-disabled.
     */
    public function reactivateOrigin(OriginServer $originServer): JsonResponse
    {
        $originServer->update([
            'is_active'             => true,
            'health_status'         => OriginServer::HEALTH_UP,
            'consecutive_failures'  => 0,
        ]);

        return response()->json([
            'ok'     => true,
            'origin' => $originServer->fresh(),
        ]);
    }
}
