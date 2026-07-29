<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrafficAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Traffic analytics endpoints. All read paths go through TrafficAggregator
 * which reads from the pre-aggregated `traffic_rollups` table.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly TrafficAggregator $aggregator) {}

    /**
     * GET /api/analytics/series
     *
     * Query params:
     *   - interval: minute | hour | day  (default hour)
     *   - from:     ISO 8601 timestamp   (default now-24h)
     *   - to:       ISO 8601 timestamp   (default now)
     *   - edge_id:  filter to one edge
     *   - domain_id:filter to one domain
     */
    public function series(Request $request): JsonResponse
    {
        $interval = in_array($request->query('interval'), ['minute', 'hour', 'day'])
            ? $request->query('interval')
            : 'hour';

        $from = $this->parseTime($request->query('from')) ?? now()->subDay();
        $to   = $this->parseTime($request->query('to'))   ?? now();

        $rows = $this->aggregator->series(
            $interval,
            CarbonImmutable::instance($from),
            CarbonImmutable::instance($to),
            $request->query('edge_id')   ? (int) $request->query('edge_id')   : null,
            $request->query('domain_id') ? (int) $request->query('domain_id') : null,
        );

        return response()->json([
            'interval' => $interval,
            'from'     => $from->toIso8601String(),
            'to'       => $to->toIso8601String(),
            'points'   => $rows,
        ]);
    }

    /**
     * GET /api/analytics/top-domains
     */
    public function topDomains(Request $request): JsonResponse
    {
        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $from = $this->parseTime($request->query('from'));
        $to   = $this->parseTime($request->query('to'));

        $rows = $this->aggregator->topDomains(
            $limit,
            $from ? CarbonImmutable::instance($from) : null,
            $to   ? CarbonImmutable::instance($to)   : null,
        );

        return response()->json([
            'limit' => $limit,
            'rows'  => $rows,
        ]);
    }

    /**
     * GET /api/analytics/top-rules
     */
    public function topRules(Request $request): JsonResponse
    {
        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $from = $this->parseTime($request->query('from'));
        $to   = $this->parseTime($request->query('to'));

        $rows = $this->aggregator->topProxyRules(
            $limit,
            $from ? CarbonImmutable::instance($from) : null,
            $to   ? CarbonImmutable::instance($to)   : null,
        );

        return response()->json([
            'limit' => $limit,
            'rows'  => $rows,
        ]);
    }

    /**
     * GET /api/analytics/summary
     *
     * Status code breakdown + cache hit ratio + total requests/bytes
     * for the given window. One round trip, used by the dashboard
     * "summary cards".
     */
    public function summary(Request $request): JsonResponse
    {
        $from = $this->parseTime($request->query('from')) ?? now()->subDay();
        $to   = $this->parseTime($request->query('to'))   ?? now();
        $domainId = $request->query('domain_id') ? (int) $request->query('domain_id') : null;

        $ciFrom = CarbonImmutable::instance($from);
        $ciTo   = CarbonImmutable::instance($to);

        $breakdown = $this->aggregator->statusBreakdown($ciFrom, $ciTo, $domainId);
        $total = array_sum($breakdown);
        $ratio = $this->aggregator->cacheHitRatio($ciFrom, $ciTo);

        // also: total bytes + requests for the window
        $totalsRow = \DB::table('traffic_rollups')
            ->selectRaw('
                COALESCE(SUM(requests), 0) AS requests,
                COALESCE(SUM(bytes_in + bytes_out), 0) AS bytes
            ')
            ->whereBetween('bucket', [$ciFrom, $ciTo])
            ->when($domainId, fn ($q) => $q->where('domain_id', $domainId))
            ->first();

        return response()->json([
            'from'    => $from->toIso8601String(),
            'to'      => $to->toIso8601String(),
            'total'   => [
                'requests' => (int) $totalsRow->requests,
                'bytes'    => (int) $totalsRow->bytes,
            ],
            'status'  => $breakdown + ['total' => $total],
            'cache_hit_ratio_pct' => $ratio,
        ]);
    }

    /**
     * POST /api/analytics/rebuild
     * Body: { from?: ISO, to?: ISO }
     *
     * Admin-only: trigger a manual rollup rebuild. Normally the
     * `traffic:rollup` artisan command runs hourly.
     */
    public function rebuild(Request $request): JsonResponse
    {
        if (! $request->user()?->is_admin) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $from = $this->parseTime($request->input('from')) ?? now()->subDay();
        $to   = $this->parseTime($request->input('to'))   ?? now();

        $count = $this->aggregator->rebuildRange(
            CarbonImmutable::instance($from)->startOfHour(),
            CarbonImmutable::instance($to)->startOfHour(),
        );
        return response()->json(['rebuilt_rows' => $count]);
    }

    protected function parseTime(mixed $v): ?\DateTimeInterface
    {
        if (! $v) return null;
        try {
            return new \DateTimeImmutable((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }
}
