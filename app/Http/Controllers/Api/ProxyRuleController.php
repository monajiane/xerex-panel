<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProxyRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProxyRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProxyRule::query()->with(['domain:id,domain', 'edgeServer:id,name,hostname', 'originServer:id,name,host,port']);

        if (! $request->user()->is_admin) {
            $query->whereHas('domain', fn ($q) => $q->where('user_id', $request->user()->id));
        }
        if ($type = $request->string('type')->toString()) {
            $query->where('type', $type);
        }
        if ($enabled = $request->has('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }
        if ($edgeId = $request->integer('edge_server_id')) {
            $query->where('edge_server_id', $edgeId);
        }
        if ($domainId = $request->integer('domain_id')) {
            $query->where('domain_id', $domainId);
        }

        return response()->json($query->orderBy('priority')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id'         => ['required', 'exists:domains,id'],
            'edge_server_id'    => ['required', 'exists:edge_servers,id'],
            'origin_server_id'  => ['required', 'exists:origin_servers,id'],
            'name'              => ['nullable', 'string', 'max:120'],
            'type'              => ['required', Rule::in(['http', 'websocket', 'tcp', 'grpc', 'sse', 'redirect'])],
            'path'              => ['nullable', 'string', 'max:1024'],
            'path_match_type'   => ['nullable', Rule::in(['exact', 'prefix', 'regex'])],
            'listen_port'       => ['nullable', 'integer', 'min:1', 'max:65535'],
            'force_https'       => ['boolean'],
            'http2_enabled'     => ['boolean'],
            'http3_enabled'     => ['boolean'],
            'priority'          => ['nullable', 'integer', 'min:0', 'max:1000'],
            'weight'            => ['nullable', 'integer', 'min:1', 'max:1000'],
            'enabled'           => ['boolean'],
            'is_primary'        => ['boolean'],
            'headers_request'   => ['nullable', 'array'],
            'headers_response'  => ['nullable', 'array'],
            'cache_rules'       => ['nullable', 'array'],
            'rate_limit'        => ['nullable', 'array'],
        ]);

        $rule = ProxyRule::create($data + [
            'path' => $data['path'] ?? '/',
        ]);

        return response()->json($rule, 201);
    }

    public function show(ProxyRule $proxyRule): JsonResponse
    {
        $proxyRule->load(['domain', 'edgeServer', 'originServer']);
        return response()->json($proxyRule);
    }

    public function update(Request $request, ProxyRule $proxyRule): JsonResponse
    {
        $data = $request->validate([
            'origin_server_id'  => ['sometimes', 'exists:origin_servers,id'],
            'name'              => ['nullable', 'string', 'max:120'],
            'type'              => ['sometimes', Rule::in(['http', 'websocket', 'tcp', 'grpc', 'sse', 'redirect'])],
            'path'              => ['sometimes', 'string', 'max:1024'],
            'path_match_type'   => ['sometimes', Rule::in(['exact', 'prefix', 'regex'])],
            'force_https'       => ['boolean'],
            'http2_enabled'     => ['boolean'],
            'http3_enabled'     => ['boolean'],
            'priority'          => ['nullable', 'integer', 'min:0', 'max:1000'],
            'weight'            => ['nullable', 'integer', 'min:1', 'max:1000'],
            'enabled'           => ['boolean'],
            'is_primary'        => ['boolean'],
            'headers_request'   => ['nullable', 'array'],
            'headers_response'  => ['nullable', 'array'],
            'cache_rules'       => ['nullable', 'array'],
            'rate_limit'        => ['nullable', 'array'],
        ]);

        $proxyRule->update($data);
        return response()->json($proxyRule);
    }

    public function destroy(ProxyRule $proxyRule): JsonResponse
    {
        $proxyRule->delete();
        return response()->json(['message' => 'Proxy rule deleted']);
    }

    public function toggle(ProxyRule $proxyRule): JsonResponse
    {
        $proxyRule->update(['enabled' => ! $proxyRule->enabled]);
        return response()->json($proxyRule);
    }
}
