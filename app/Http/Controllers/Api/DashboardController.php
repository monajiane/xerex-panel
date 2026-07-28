<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\EdgeServer;
use App\Models\HealthCheck;
use App\Models\OriginServer;
use App\Models\ProxyRule;
use App\Models\TrafficLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard metrics & overview endpoints.
 */
class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->is_admin;

        $edgeOnline = EdgeServer::where('status', EdgeServer::STATUS_ONLINE)->count();
        $edgeTotal  = EdgeServer::count();
        $originUp   = OriginServer::where('health_status', OriginServer::HEALTH_UP)->count();
        $originTotal = OriginServer::count();
        $domainQuery = Domain::query();
        if (! $isAdmin) $domainQuery->where('user_id', $user->id);
        $domainTotal = $domainQuery->count();
        $domainSslOk = (clone $domainQuery)->where('ssl_status', Domain::SSL_ACTIVE)->count();
        $rulesActive = ProxyRule::where('enabled', true)->count();

        // Last 24h traffic (bytes)
        $traffic24h = TrafficLog::where('logged_at', '>=', now()->subDay())
            ->selectRaw('COALESCE(SUM(bytes_sent + bytes_received), 0) as bytes, COUNT(*) as requests')
            ->first();

        return response()->json([
            'edges'  => ['online' => $edgeOnline, 'total' => $edgeTotal],
            'origins'=> ['up' => $originUp, 'total' => $originTotal],
            'domains'=> ['total' => $domainTotal, 'ssl_active' => $domainSslOk],
            'rules'  => ['active' => $rulesActive],
            'traffic_24h' => [
                'bytes'    => (int) $traffic24h->bytes,
                'requests' => (int) $traffic24h->requests,
            ],
        ]);
    }

    /**
     * Time-series of request counts (last 24h, 1h buckets).
     */
    public function trafficSeries(Request $request): JsonResponse
    {
        $rows = TrafficLog::where('logged_at', '>=', now()->subDay())
            ->selectRaw("date_trunc('hour', logged_at) as bucket, COUNT(*) as req, COALESCE(SUM(bytes_sent + bytes_received), 0) as bytes")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return response()->json($rows);
    }

    public function recentHealthChecks(Request $request): JsonResponse
    {
        $checks = HealthCheck::with('checkable')
            ->orderByDesc('checked_at')
            ->limit(50)
            ->get();

        return response()->json($checks);
    }
}
