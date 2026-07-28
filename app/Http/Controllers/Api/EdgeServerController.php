<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EdgeServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD + actions for edge servers.
 */
class EdgeServerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EdgeServer::query();

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($location = $request->string('location')->toString()) {
            $query->where('location', 'like', "%{$location}%");
        }
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('hostname', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $edges = $query->orderByDesc('id')->paginate($request->integer('per_page', 25));

        return response()->json($edges);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:120'],
            'hostname'       => ['required', 'string', 'max:255', 'unique:edge_servers,hostname'],
            'ip_address'     => ['required', 'ip'],
            'ipv6_address'   => ['nullable', 'ip'],
            'location'       => ['nullable', 'string', 'max:120'],
            'country_code'   => ['nullable', 'string', 'size:2'],
            'region'         => ['nullable', 'string', 'max:120'],
            'datacenter'     => ['nullable', 'string', 'max:120'],
            'cpu_cores'      => ['nullable', 'integer', 'min:1'],
            'ram_mb'         => ['nullable', 'integer', 'min:128'],
            'disk_gb'        => ['nullable', 'integer', 'min:1'],
            'bandwidth_mbps' => ['nullable', 'integer', 'min:1'],
            'capabilities'   => ['nullable', 'array'],
            'meta'           => ['nullable', 'array'],
        ]);

        $edge = EdgeServer::create($data + [
            'agent_token' => EdgeServer::generateAgentToken(),
            'status'      => EdgeServer::STATUS_PROVISIONING,
        ]);

        activity()->causedBy($request->user())->performedOn($edge)
            ->log('edge_server.created');

        return response()->json([
            'edge'  => $edge,
            'token' => $edge->agent_token, // only shown once
        ], 201);
    }

    public function show(EdgeServer $edgeServer): JsonResponse
    {
        $edgeServer->loadCount('proxyRules');
        return response()->json($edgeServer);
    }

    public function update(Request $request, EdgeServer $edgeServer): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:120'],
            'hostname'       => ['sometimes', 'string', 'max:255', Rule::unique('edge_servers', 'hostname')->ignore($edgeServer->id)],
            'ip_address'     => ['sometimes', 'ip'],
            'ipv6_address'   => ['nullable', 'ip'],
            'location'       => ['nullable', 'string', 'max:120'],
            'country_code'   => ['nullable', 'string', 'size:2'],
            'status'         => ['sometimes', Rule::in([
                EdgeServer::STATUS_PROVISIONING, EdgeServer::STATUS_ONLINE,
                EdgeServer::STATUS_OFFLINE, EdgeServer::STATUS_DEGRADED,
                EdgeServer::STATUS_MAINTENANCE,
            ])],
            'capabilities'   => ['nullable', 'array'],
            'meta'           => ['nullable', 'array'],
        ]);

        $edgeServer->update($data);

        return response()->json($edgeServer);
    }

    public function destroy(EdgeServer $edgeServer): JsonResponse
    {
        $edgeServer->delete();
        return response()->json(['message' => 'Edge server deleted']);
    }

    public function rotateToken(EdgeServer $edgeServer): JsonResponse
    {
        $edgeServer->update([
            'agent_token' => EdgeServer::generateAgentToken(),
        ]);

        return response()->json([
            'token' => $edgeServer->agent_token,
        ]);
    }

    /**
     * Test connection (ping the agent endpoint).
     */
    public function testConnection(EdgeServer $edgeServer): JsonResponse
    {
        $port = 8443; // default agent port
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($edgeServer->ip_address, $port, $errno, $errstr, 3);

        if (! $sock) {
            return response()->json([
                'success' => false,
                'error'   => "Cannot connect to agent at {$edgeServer->ip_address}:{$port} - {$errstr}",
            ], 502);
        }

        fclose($sock);

        $edgeServer->update(['last_seen_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Connection successful',
        ]);
    }
}
