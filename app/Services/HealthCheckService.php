<?php

namespace App\Services;

use App\Events\FailoverTriggered;
use App\Events\OriginHealthChanged;
use App\Jobs\SyncEdgeConfig;
use App\Models\EdgeServer;
use App\Models\HealthCheck;
use App\Models\OriginServer;
use App\Models\ProxyRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HealthCheckService - performs periodic health probes against origin and edge
 * servers and drives automatic failover when an origin consistently fails.
 *
 * Probe types:
 *  - HTTP:    full GET against the configured health_check_path with expected status
 *  - TCP:     socket open against host:port
 *  - SSL:     TLS handshake + cert expiry check
 *  - ICMP/Ping: optional (server-side disabled in many DCs)
 *
 * Failover logic:
 *  - After N consecutive failed checks, the origin is marked health_status=DOWN,
 *    consecutive_failures is incremented, and (when threshold met) is_active=false.
 *  - When the origin recovers and passes the success_threshold checks, it is
 *    re-enabled and a FailoverTriggered event is fired so edges can re-sync.
 */
class HealthCheckService
{
    public function __construct(
        protected int $timeoutSeconds = 5,
        protected int $failThreshold = 3,
        protected int $successThreshold = 2,
        protected int $disableAfterFailures = 3,
    ) {}

    public static function make(): self
    {
        return new self(
            timeoutSeconds:    (int) config('xerex.health.timeout', 5),
            failThreshold:     (int) config('xerex.health.fail_threshold', 3),
            successThreshold:  (int) config('xerex.health.success_threshold', 2),
            disableAfterFailures: (int) config('xerex.health.fail_threshold', 3),
        );
    }

    // ============ Public entry points ============

    /**
     * Run an immediate check against a single origin (regardless of interval).
     * Persists the HealthCheck row, updates health_status, and applies failover
     * when appropriate.
     */
    public function checkOrigin(OriginServer $origin): HealthCheck
    {
        $check = $origin->health_check_enabled
            ? $this->probeHttp($origin)
            : $this->probeTcp($origin);

        $healthCheck = $origin->healthChecks()->create($check);

        $this->applyResult($origin, $healthCheck);

        return $healthCheck;
    }

    /**
     * Check an edge server's reachability (TCP + ICMP-style ping).
     * Edges do not auto-disable - they are simply marked offline in telemetry.
     */
    public function checkEdge(EdgeServer $edge): HealthCheck
    {
        $check = $this->probeTcpForEdge($edge);
        $healthCheck = $edge->healthChecks()->create($check);

        // Reflect edge reachability into its own status field
        if ($check['status'] === HealthCheck::STATUS_UP) {
            if ($edge->status !== EdgeServer::STATUS_ONLINE) {
                $edge->update(['status' => EdgeServer::STATUS_ONLINE, 'last_seen_at' => now()]);
            }
        } else {
            if ($edge->status === EdgeServer::STATUS_ONLINE) {
                $edge->update(['status' => EdgeServer::STATUS_DEGRADED]);
            }
        }

        return $healthCheck;
    }

    /**
     * Run scheduled checks against every active origin whose interval has elapsed.
     * Designed to be invoked by `xerex:health:check` console command.
     *
     * @return array{origins: int, edges: int, failed: int, disabled: int, reenabled: int}
     */
    public function runScheduledChecks(): array
    {
        $stats = ['origins' => 0, 'edges' => 0, 'failed' => 0, 'disabled' => 0, 'reenabled' => 0];

        // Origins whose health_check_enabled = true and (no last check OR interval elapsed)
        $origins = OriginServer::query()
            ->where('health_check_enabled', true)
            ->where('is_active', true)
            ->get()
            ->filter(function (OriginServer $o) {
                $interval = (int) ($o->health_check_interval ?: config('xerex.health.interval', 30));
                return ! $o->last_health_check_at
                    || $o->last_health_check_at->lt(Carbon::now()->subSeconds($interval));
            });

        foreach ($origins as $origin) {
            $stats['origins']++;
            $check = $this->checkOrigin($origin);
            if (! $check->isUp()) {
                $stats['failed']++;
            }
            if ($origin->wasChanged('is_active') && $origin->is_active === false) {
                $stats['disabled']++;
            }
        }

        // Edges: simple reachability probe
        $edges = EdgeServer::query()
            ->whereIn('status', [EdgeServer::STATUS_ONLINE, EdgeServer::STATUS_DEGRADED])
            ->get()
            ->filter(fn (EdgeServer $e) => $e->last_seen_at === null
                || $e->last_seen_at->lt(Carbon::now()->subMinutes(2)));

        foreach ($edges as $edge) {
            $stats['edges']++;
            $this->checkEdge($edge);
        }

        // Re-enable previously-disabled origins that have recovered
        $stats['reenabled'] = $this->reenableRecoveredOrigins();

        return $stats;
    }

    /**
     * Find origins that were previously marked DOWN and have now produced
     * `success_threshold` consecutive successful checks. Re-activate them and
     * dispatch a FailoverTriggered event so edges re-sync their config.
     */
    public function reenableRecoveredOrigins(): int
    {
        $recovered = 0;

        $origins = OriginServer::query()
            ->where('is_active', false)
            ->where('health_status', OriginServer::HEALTH_DOWN)
            ->get();

        foreach ($origins as $origin) {
            // Count the consecutive UP checks at the tail of recent history
            $recent = $origin->healthChecks()
                ->orderByDesc('checked_at')
                ->limit($this->successThreshold + 2)
                ->get();

            $consecutiveUp = 0;
            foreach ($recent as $check) {
                if ($check->isUp()) {
                    $consecutiveUp++;
                } else {
                    break;
                }
            }

            if ($consecutiveUp >= $this->successThreshold) {
                $origin->update([
                    'is_active'             => true,
                    'health_status'         => OriginServer::HEALTH_UP,
                    'consecutive_failures'  => 0,
                ]);

                FailoverTriggered::dispatch($origin, 'recovered');
                $recovered++;

                Log::info("Origin {$origin->name} re-enabled after {$consecutiveUp} successful checks");
            }
        }

        return $recovered;
    }

    // ============ Probes ============

    /**
     * Perform an HTTP probe against the origin's health_check_path.
     * Returns the HealthCheck row payload (not yet persisted).
     *
     * @return array<string, mixed>
     */
    public function probeHttp(OriginServer $origin): array
    {
        $url  = rtrim($origin->getUpstreamUrl(), '/') . ($origin->health_check_path ?: '/');
        $expected = (int) ($origin->health_check_expected_status ?: 200);

        $start = microtime(true);
        $dnsMs = $connectMs = $tlsMs = $firstByteMs = null;
        $status = HealthCheck::STATUS_DOWN;
        $responseCode = null;
        $error = null;
        $headers = null;

        try {
            $pending = Http::withOptions([
                'connect_timeout' => $this->timeoutSeconds,
                'timeout'         => $this->timeoutSeconds,
            ])->withHeaders([
                'User-Agent' => 'Xerex-HealthCheck/1.0',
            ]);

            // Path-based SNI override
            if (! empty($origin->ssl_sni)) {
                $pending = $pending->withOptions(['curl' => [CURLOPT_RESOLVE => [
                    $origin->ssl_sni . ':' . $origin->port . ':' . $origin->host,
                ]]]);
            }

            if ($origin->ssl_verify === false) {
                $pending = $pending->withoutVerifying();
            }

            $response = $pending->get($url);
            $responseCode = $response->status();
            $headers = $this->extractHeaders($response->headers());
            $totalMs = (int) ((microtime(true) - $start) * 1000);

            // Map by status code
            if ($responseCode === $expected) {
                $status = HealthCheck::STATUS_UP;
            } elseif ($responseCode >= 500) {
                $status = HealthCheck::STATUS_DOWN;
            } else {
                // 4xx is "degraded" - the host is up but not serving correctly
                $status = HealthCheck::STATUS_DEGRADED;
            }

            return [
                'check_type'   => HealthCheck::TYPE_HTTP,
                'target'       => $url,
                'status'       => $status,
                'response_code'=> $responseCode,
                'latency_ms'   => $totalMs,
                'dns_ms'       => $dnsMs,
                'connect_ms'   => $connectMs,
                'tls_ms'       => $tlsMs,
                'first_byte_ms'=> $firstByteMs,
                'error'        => null,
                'response_headers' => $headers,
                'region'       => config('app.region'),
                'source_ip'    => $origin->host,
                'checked_at'   => now(),
            ];
        } catch (\Throwable $e) {
            $totalMs = (int) ((microtime(true) - $start) * 1000);
            $error = $e->getMessage();

            // Determine if it's a timeout
            $status = str_contains(strtolower($error), 'timeout')
                ? HealthCheck::STATUS_TIMEOUT
                : HealthCheck::STATUS_DOWN;

            return [
                'check_type'    => HealthCheck::TYPE_HTTP,
                'target'        => $url,
                'status'        => $status,
                'response_code' => null,
                'latency_ms'    => $totalMs,
                'error'         => $error,
                'region'        => config('app.region'),
                'source_ip'     => $origin->host,
                'checked_at'    => now(),
            ];
        }
    }

    /**
     * Perform a TCP socket open check against host:port.
     *
     * @return array<string, mixed>
     */
    public function probeTcp(OriginServer $origin): array
    {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $connection = @fsockopen($origin->host, $origin->port, $errno, $errstr, $this->timeoutSeconds);

        if ($connection) {
            fclose($connection);
            $latency = (int) ((microtime(true) - $start) * 1000);
            return [
                'check_type'   => HealthCheck::TYPE_TCP,
                'target'       => $origin->host . ':' . $origin->port,
                'status'       => HealthCheck::STATUS_UP,
                'latency_ms'   => $latency,
                'checked_at'   => now(),
            ];
        }

        $latency = (int) ((microtime(true) - $start) * 1000);
        return [
            'check_type'   => HealthCheck::TYPE_TCP,
            'target'       => $origin->host . ':' . $origin->port,
            'status'       => HealthCheck::STATUS_DOWN,
            'latency_ms'   => $latency,
            'error'        => $errstr ?: "errno=$errno",
            'checked_at'   => now(),
        ];
    }

    /**
     * TCP probe targeting an edge server.
     *
     * @return array<string, mixed>
     */
    public function probeTcpForEdge(EdgeServer $edge): array
    {
        $host = $edge->ip_address ?: $edge->hostname;
        $port = $this->edgeAgentPort();

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $connection = @fsockopen($host, $port, $errno, $errstr, $this->timeoutSeconds);

        if ($connection) {
            fclose($connection);
            $latency = (int) ((microtime(true) - $start) * 1000);
            return [
                'check_type'   => HealthCheck::TYPE_TCP,
                'target'       => $host . ':' . $port,
                'status'       => HealthCheck::STATUS_UP,
                'latency_ms'   => $latency,
                'region'       => $edge->location,
                'source_ip'    => $edge->ip_address,
                'checked_at'   => now(),
            ];
        }

        $latency = (int) ((microtime(true) - $start) * 1000);
        return [
            'check_type'   => HealthCheck::TYPE_TCP,
            'target'       => $host . ':' . $port,
            'status'       => HealthCheck::STATUS_DOWN,
            'latency_ms'   => $latency,
            'error'        => $errstr ?: "errno=$errno",
            'region'       => $edge->location,
            'source_ip'    => $edge->ip_address,
            'checked_at'   => now(),
        ];
    }

    // ============ Internal logic ============

    /**
     * Apply the result of a probe to the origin's counters and trigger
     * failover when the failure threshold is crossed.
     */
    protected function applyResult(OriginServer $origin, HealthCheck $check): void
    {
        $previousStatus = $origin->health_status;
        $wasActive = $origin->is_active;

        // Update counters
        if ($check->isUp()) {
            $newFailures = 0;
            $newStatus = OriginServer::HEALTH_UP;
        } else {
            $newFailures = ($origin->consecutive_failures ?? 0) + 1;
            $newStatus = OriginServer::HEALTH_DOWN;
        }

        $shouldDisable = $newFailures >= $this->disableAfterFailures && $wasActive;

        DB::transaction(function () use ($origin, $newStatus, $newFailures, $shouldDisable) {
            $origin->health_status        = $newStatus;
            $origin->consecutive_failures = $newFailures;
            $origin->last_health_check_at = now();

            if ($shouldDisable) {
                $origin->is_active = false;
            }

            $origin->save();
        });

        // Broadcast event (used by listener to re-sync edges)
        if ($previousStatus !== $newStatus) {
            OriginHealthChanged::dispatch($origin, $previousStatus, $newStatus);
        }

        if ($shouldDisable) {
            Log::warning("Origin {$origin->name} disabled after {$newFailures} consecutive failures");
            FailoverTriggered::dispatch($origin, 'disabled');

            // If this origin belongs to a failover group, try to promote a
            // healthy sibling so the upstream list updates immediately.
            if ($origin->failover_group) {
                try {
                    app(FailoverGroupService::class)->promoteReplacement($origin);
                } catch (\Throwable $e) {
                    Log::error("FailoverGroup promotion failed: {$e->getMessage()}", [
                        'origin_id' => $origin->id,
                        'group'     => $origin->failover_group,
                    ]);
                }
            }
        }
    }

    /**
     * Determine the agent port to probe for an edge.
     */
    protected function edgeAgentPort(): int
    {
        return (int) config('xerex.edge.agent_port', 8443);
    }

    /**
     * Trim HTTP response headers to a sensible size for persistence.
     *
     * @param array<string, array<int, string>> $headers
     * @return array<string, string>
     */
    protected function extractHeaders(array $headers): array
    {
        $allowed = ['Server', 'Content-Type', 'X-Powered-By', 'X-Request-Id', 'Date', 'Via'];
        $out = [];
        foreach ($headers as $name => $values) {
            if (in_array($name, $allowed, true)) {
                $out[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
            }
        }
        return $out;
    }
}
