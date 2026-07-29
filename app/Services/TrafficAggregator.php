<?php

namespace App\Services;

use App\Models\TrafficLog;
use App\Models\TrafficRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Traffic rollup computation & read-side queries.
 *
 * The aggregator never scans the raw `traffic_logs` table for read
 * operations – it always queries the pre-computed `traffic_rollups`
 * hourly buckets. The write path (rebuildForHour) is what keeps the
 * rollup table up to date.
 */
class TrafficAggregator
{
    /** Bucket size in seconds. */
    public const BUCKET_SECONDS = 3600;

    /**
     * Recompute the rollup row for a single hour. Idempotent: safe to
     * call repeatedly; uses upsert semantics.
     *
     * The query runs entirely in the database – we never load the
     * individual traffic rows into PHP.
     */
    public function rebuildForHour(CarbonImmutable $hourStart): int
    {
        $hourEnd = $hourStart->addHour();

        // We aggregate in a single query and upsert the result per
        // (edge, domain, proxy_rule) tuple.
        $rows = DB::table('traffic_logs')
            ->selectRaw('
                edge_server_id,
                domain_id,
                proxy_rule_id,
                COUNT(*) AS requests,
                COALESCE(SUM(bytes_sent), 0) AS bytes_out,
                COALESCE(SUM(bytes_received), 0) AS bytes_in,
                SUM(CASE WHEN cached = 1 THEN 1 ELSE 0 END) AS cache_hits,
                SUM(CASE WHEN cached = 0 THEN 1 ELSE 0 END) AS cache_misses,
                SUM(CASE WHEN response_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS status_2xx,
                SUM(CASE WHEN response_code BETWEEN 300 AND 399 THEN 1 ELSE 0 END) AS status_3xx,
                SUM(CASE WHEN response_code BETWEEN 400 AND 499 THEN 1 ELSE 0 END) AS status_4xx,
                SUM(CASE WHEN response_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS status_5xx,
                COALESCE(SUM(request_time_ms), 0) AS request_time_sum_ms,
                COALESCE(SUM(upstream_time_ms), 0) AS upstream_time_sum_ms,
                COUNT(DISTINCT client_ip) AS unique_clients
            ')
            ->where('logged_at', '>=', $hourStart)
            ->where('logged_at', '<', $hourEnd)
            ->groupBy('edge_server_id', 'domain_id', 'proxy_rule_id')
            ->get();

        $now = now();
        $inserted = 0;
        foreach ($rows as $r) {
            TrafficRollup::updateOrCreate(
                [
                    'edge_server_id' => $r->edge_server_id,
                    'domain_id'      => $r->domain_id,
                    'proxy_rule_id'  => $r->proxy_rule_id,
                    'bucket'         => $hourStart,
                ],
                [
                    'requests'             => (int) $r->requests,
                    'bytes_in'             => (int) $r->bytes_in,
                    'bytes_out'            => (int) $r->bytes_out,
                    'cache_hits'           => (int) $r->cache_hits,
                    'cache_misses'         => (int) $r->cache_misses,
                    'status_2xx'           => (int) $r->status_2xx,
                    'status_3xx'           => (int) $r->status_3xx,
                    'status_4xx'           => (int) $r->status_4xx,
                    'status_5xx'           => (int) $r->status_5xx,
                    'request_time_sum_ms'  => (int) $r->request_time_sum_ms,
                    'upstream_time_sum_ms' => (int) $r->upstream_time_sum_ms,
                    'unique_clients'       => (int) $r->unique_clients,
                    'updated_at'           => $now,
                    'created_at'           => $now,
                ]
            );
            $inserted++;
        }
        return $inserted;
    }

    /**
     * Rebuild every hour bucket between $from and $to (inclusive).
     * Used by the daily artisan command and by the "rebuild" button in
     * the UI.
     */
    public function rebuildRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $from = $from->startOfHour();
        $total = 0;
        $cursor = $from;
        while ($cursor <= $to) {
            $total += $this->rebuildForHour($cursor);
            $cursor = $cursor->addHour();
        }
        return $total;
    }

    /**
     * Top-N domains by request count, optionally within a time window.
     */
    public function topDomains(int $limit = 10, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        $q = DB::table('traffic_rollups')
            ->join('domains', 'domains.id', '=', 'traffic_rollups.domain_id')
            ->select(
                'domains.id',
                'domains.domain',
                DB::raw('SUM(traffic_rollups.requests) AS requests'),
                DB::raw('SUM(traffic_rollups.bytes_in + traffic_rollups.bytes_out) AS bytes'),
                DB::raw('SUM(traffic_rollups.status_4xx) AS status_4xx'),
                DB::raw('SUM(traffic_rollups.status_5xx) AS status_5xx'),
            )
            ->groupBy('domains.id', 'domains.domain')
            ->orderByDesc('requests')
            ->limit($limit);
        $this->applyWindow($q, $from, $to);
        return $q->get();
    }

    /**
     * Top-N proxy rules by traffic. Useful for capacity planning.
     */
    public function topProxyRules(int $limit = 10, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        $q = DB::table('traffic_rollups')
            ->join('proxy_rules', 'proxy_rules.id', '=', 'traffic_rollups.proxy_rule_id')
            ->leftJoin('domains', 'domains.id', '=', 'proxy_rules.domain_id')
            ->select(
                'proxy_rules.id',
                'proxy_rules.uuid',
                'proxy_rules.type',
                'domains.domain',
                DB::raw('SUM(traffic_rollups.requests) AS requests'),
                DB::raw('SUM(traffic_rollups.bytes_in + traffic_rollups.bytes_out) AS bytes'),
            )
            ->groupBy('proxy_rules.id', 'proxy_rules.uuid', 'proxy_rules.type', 'domains.domain')
            ->orderByDesc('requests')
            ->limit($limit);
        $this->applyWindow($q, $from, $to);
        return $q->get();
    }

    /**
     * Time series of total requests, cache hits and bytes for charting.
     * Returns rows ordered by bucket ascending.
     */
    public function series(
        string $interval = 'hour',
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $edgeId = null,
        ?int $domainId = null,
    ): Collection {
        $from ??= now()->subDay();
        $to   ??= now();

        $trunc = match ($interval) {
            'minute' => "date_trunc('minute', bucket)",
            'day'    => "date_trunc('day', bucket)",
            default  => "date_trunc('hour', bucket)",
        };

        $q = DB::table('traffic_rollups')
            ->selectRaw("
                $trunc AS bucket,
                SUM(requests)              AS requests,
                SUM(bytes_in + bytes_out)  AS bytes,
                SUM(cache_hits)            AS cache_hits,
                SUM(cache_misses)          AS cache_misses,
                SUM(status_2xx)            AS status_2xx,
                SUM(status_3xx)            AS status_3xx,
                SUM(status_4xx)            AS status_4xx,
                SUM(status_5xx)            AS status_5xx
            ")
            ->whereBetween('bucket', [$from, $to])
            ->groupBy('bucket')
            ->orderBy('bucket');
        if ($edgeId)   $q->where('edge_server_id', $edgeId);
        if ($domainId) $q->where('domain_id', $domainId);
        return $q->get();
    }

    /**
     * Distribution of response codes (2xx/3xx/4xx/5xx) inside the window.
     */
    public function statusBreakdown(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?int $domainId = null): array
    {
        $from ??= now()->subDay();
        $to   ??= now();
        $q = DB::table('traffic_rollups')
            ->selectRaw('
                COALESCE(SUM(status_2xx),0) AS s2,
                COALESCE(SUM(status_3xx),0) AS s3,
                COALESCE(SUM(status_4xx),0) AS s4,
                COALESCE(SUM(status_5xx),0) AS s5
            ')
            ->whereBetween('bucket', [$from, $to]);
        if ($domainId) $q->where('domain_id', $domainId);
        $r = $q->first();
        return [
            '2xx' => (int) $r->s2,
            '3xx' => (int) $r->s3,
            '4xx' => (int) $r->s4,
            '5xx' => (int) $r->s5,
        ];
    }

    /**
     * Cache hit ratio (%) inside the window.
     */
    public function cacheHitRatio(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): float
    {
        $from ??= now()->subDay();
        $to   ??= now();
        $r = DB::table('traffic_rollups')
            ->selectRaw('
                COALESCE(SUM(cache_hits), 0)   AS hits,
                COALESCE(SUM(cache_misses), 0) AS misses
            ')
            ->whereBetween('bucket', [$from, $to])
            ->first();
        $hits = (int) $r->hits;
        $misses = (int) $r->misses;
        $total = $hits + $misses;
        return $total === 0 ? 0.0 : round($hits * 100 / $total, 2);
    }

    /**
     * Apply a from/to window to a query builder.
     */
    protected function applyWindow($q, ?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        if ($from) $q->where('traffic_rollups.bucket', '>=', $from);
        if ($to)   $q->where('traffic_rollups.bucket', '<=', $to);
    }
}
