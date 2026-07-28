<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EdgeServer;
use App\Models\ProxyRule;
use App\Models\TrafficLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints called by the Xerex Edge Agent (Golang).
 * Auth: edge.auth middleware (bearer token per edge).
 *
 * Responsibilities:
 *  - Provide the edge with its current configuration
 *  - Receive telemetry updates
 *  - Receive traffic log batches
 *  - Receive health check results
 */
class AgentController extends Controller
{
    /**
     * GET /api/agent/config
     * Returns the full set of proxy rules assigned to this edge.
     */
    public function config(Request $request): JsonResponse
    {
        /** @var EdgeServer $edge */
        $edge = $request->attributes->get('edge_server');

        $rules = ProxyRule::with(['domain', 'originServer'])
            ->where('edge_server_id', $edge->id)
            ->where('enabled', true)
            ->get()
            ->map(function (ProxyRule $rule) {
                return [
                    'id'                 => $rule->id,
                    'uuid'               => $rule->uuid,
                    'domain'             => $rule->domain?->domain,
                    'type'               => $rule->type,
                    'path'               => $rule->path,
                    'path_match_type'    => $rule->path_match_type,
                    'listen_port'        => $rule->listen_port,
                    'force_https'        => $rule->force_https,
                    'http2_enabled'      => $rule->http2_enabled,
                    'http3_enabled'      => $rule->http3_enabled,
                    'is_primary'         => $rule->is_primary,
                    'priority'           => $rule->priority,
                    'weight'             => $rule->weight,
                    'headers_request'    => $rule->headers_request,
                    'headers_response'   => $rule->headers_response,
                    'cache_rules'        => $rule->cache_rules,
                    'rate_limit'         => $rule->rate_limit,
                    'access_rules'       => $rule->access_rules,
                    'origin' => [
                        'url'        => $rule->originServer?->getUpstreamUrl(),
                        'host'       => $rule->originServer?->host,
                        'port'       => $rule->originServer?->port,
                        'protocol'   => $rule->originServer?->protocol,
                        'weight'     => $rule->originServer?->weight,
                        'max_fails'  => $rule->originServer?->max_fails,
                        'fail_timeout' => $rule->originServer?->fail_timeout,
                        'health_check_path' => $rule->originServer?->health_check_path,
                        'connect_timeout' => $rule->originServer?->connect_timeout,
                        'read_timeout'    => $rule->originServer?->read_timeout,
                        'send_timeout'    => $rule->originServer?->send_timeout,
                    ],
                ];
            });

        return response()->json([
            'edge' => [
                'id'        => $edge->id,
                'uuid'      => $edge->uuid,
                'name'      => $edge->name,
                'hostname'  => $edge->hostname,
                'capabilities' => $edge->capabilities,
            ],
            'rules' => $rules,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/agent/telemetry
     * Periodic resource/connection metrics.
     */
    public function telemetry(Request $request): JsonResponse
    {
        /** @var EdgeServer $edge */
        $edge = $request->attributes->get('edge_server');

        $data = $request->validate([
            'agent_version'         => ['nullable', 'string'],
            'cpu_usage'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ram_usage'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disk_usage'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bandwidth_in_bytes'    => ['nullable', 'integer', 'min:0'],
            'bandwidth_out_bytes'   => ['nullable', 'integer', 'min:0'],
            'active_connections'    => ['nullable', 'integer', 'min:0'],
            'requests_per_second'   => ['nullable', 'integer', 'min:0'],
            'capabilities'          => ['nullable', 'array'],
        ]);

        $edge->fill($data);
        $edge->last_seen_at = now();
        $edge->status = EdgeServer::STATUS_ONLINE;
        $edge->save();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/agent/traffic
     * Bulk upload of access log lines.
     */
    public function traffic(Request $request): JsonResponse
    {
        /** @var EdgeServer $edge */
        $edge = $request->attributes->get('edge_server');

        $data = $request->validate([
            'logs'                    => ['required', 'array', 'max:1000'],
            'logs.*.domain_id'        => ['nullable', 'integer'],
            'logs.*.proxy_rule_id'    => ['nullable', 'integer'],
            'logs.*.method'           => ['nullable', 'string', 'max:8'],
            'logs.*.scheme'           => ['nullable', 'string', 'max:8'],
            'logs.*.url'              => ['nullable', 'string', 'max:2048'],
            'logs.*.host'             => ['nullable', 'string', 'max:255'],
            'logs.*.path'             => ['nullable', 'string', 'max:1024'],
            'logs.*.response_code'    => ['nullable', 'integer'],
            'logs.*.bytes_sent'       => ['nullable', 'integer'],
            'logs.*.bytes_received'   => ['nullable', 'integer'],
            'logs.*.request_time_ms'  => ['nullable', 'integer'],
            'logs.*.upstream_time_ms' => ['nullable', 'integer'],
            'logs.*.client_ip'        => ['nullable', 'string', 'max:45'],
            'logs.*.user_agent'       => ['nullable', 'string', 'max:512'],
            'logs.*.referer'          => ['nullable', 'string', 'max:1024'],
            'logs.*.protocol'         => ['nullable', 'string', 'max:16'],
            'logs.*.cached'           => ['nullable', 'boolean'],
            'logs.*.cache_status'     => ['nullable', 'string', 'max:32'],
            'logs.*.logged_at'        => ['required', 'date'],
        ]);

        $inserted = 0;
        foreach ($data['logs'] as $log) {
            TrafficLog::create($log + [
                'edge_server_id' => $edge->id,
            ]);
            $inserted++;
        }

        return response()->json(['inserted' => $inserted]);
    }

    /**
     * POST /api/agent/health
     * Health check result from the agent side.
     */
    public function health(Request $request): JsonResponse
    {
        /** @var EdgeServer $edge */
        $edge = $request->attributes->get('edge_server');

        $data = $request->validate([
            'check_type'         => ['required', 'string'],
            'target'             => ['required', 'string'],
            'status'             => ['required', 'string'],
            'response_code'      => ['nullable', 'integer'],
            'latency_ms'         => ['nullable', 'integer'],
            'error'              => ['nullable', 'string'],
            'origin_server_id'   => ['nullable', 'integer'],
        ]);

        $checkable = $data['origin_server_id'] ?? null
            ? \App\Models\OriginServer::find($data['origin_server_id'])
            : $edge;

        if (! $checkable) {
            return response()->json(['ok' => false, 'error' => 'checkable not found'], 404);
        }

        $check = $checkable->healthChecks()->create([
            'check_type' => $data['check_type'],
            'target'     => $data['target'],
            'status'     => $data['status'],
            'response_code' => $data['response_code'] ?? null,
            'latency_ms' => $data['latency_ms'] ?? null,
            'error'      => $data['error'] ?? null,
            'region'     => $edge->location,
            'source_ip'  => $request->ip(),
            'checked_at' => now(),
        ]);

        // Update origin health_status if applicable
        if ($checkable instanceof \App\Models\OriginServer) {
            $checkable->markHealthCheck($data['status'] === 'up');
        }

        return response()->json(['ok' => true, 'id' => $check->id]);
    }
}
