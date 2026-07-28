<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OriginServer;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OriginServerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = OriginServer::query();

        if ($request->user() && ! $request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }
        if ($health = $request->string('health_status')->toString()) {
            $query->where('health_status', $health);
        }
        if ($group = $request->string('failover_group')->toString()) {
            $query->where('failover_group', $group);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('host', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('failover_priority')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'host'              => ['required', 'string', 'max:255'],
            'port'              => ['required', 'integer', 'min:1', 'max:65535'],
            'protocol'          => ['required', Rule::in(['http', 'https', 'grpc', 'tcp'])],
            'upstream_type'     => ['nullable', Rule::in(['web', 'websocket', 'tcp', 'grpc', 'sse'])],
            'ssl_enabled'       => ['boolean'],
            'ssl_verify'        => ['boolean'],
            'weight'            => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_fails'         => ['nullable', 'integer', 'min:1', 'max:1000'],
            'fail_timeout'      => ['nullable', 'integer', 'min:1', 'max:3600'],
            'health_check_path' => ['nullable', 'string', 'max:255'],
            'headers'           => ['nullable', 'array'],
            'failover_group'    => ['nullable', 'string', 'max:64'],
            'failover_priority' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $data['user_id'] = $request->user()->id;

        // If joining a failover group, set the new priority below existing min so
        // the new origin is the leader. (The user can reorder later.)
        if (! empty($data['failover_group']) && ! isset($data['failover_priority'])) {
            $data['failover_priority'] = OriginServer::where('failover_group', $data['failover_group'])
                ->min('failover_priority') - 1;
        }

        $origin = OriginServer::create($data);

        return response()->json($origin, 201);
    }

    public function show(OriginServer $originServer): JsonResponse
    {
        $this->authorizeAccess(request()->user(), $originServer);
        $originServer->loadMissing('siblings');
        return response()->json($originServer);
    }

    public function update(Request $request, OriginServer $originServer): JsonResponse
    {
        $this->authorizeAccess($request->user(), $originServer);

        $data = $request->validate([
            'name'              => ['sometimes', 'string', 'max:120'],
            'host'              => ['sometimes', 'string', 'max:255'],
            'port'              => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'protocol'          => ['sometimes', Rule::in(['http', 'https', 'grpc', 'tcp'])],
            'ssl_enabled'       => ['boolean'],
            'ssl_verify'        => ['boolean'],
            'weight'            => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active'         => ['boolean'],
            'headers'           => ['nullable', 'array'],
            'failover_group'    => ['nullable', 'string', 'max:64'],
            'failover_priority' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $originServer->update($data);

        return response()->json($originServer);
    }

    public function destroy(OriginServer $originServer): JsonResponse
    {
        $this->authorizeAccess(request()->user(), $originServer);
        $originServer->delete();
        return response()->json(['message' => 'Origin server deleted']);
    }

    public function test(OriginServer $originServer): JsonResponse
    {
        $this->authorizeAccess(request()->user(), $originServer);

        $check = app(HealthCheckService::class)->checkOrigin($originServer->fresh());

        return response()->json([
            'success'      => $check?->isUp() ?? false,
            'status'       => $check?->status,
            'response_code'=> $check?->response_code,
            'latency_ms'   => $check?->latency_ms,
            'error'        => $check?->error,
        ]);
    }

    private function authorizeAccess($user, OriginServer $origin): void
    {
        if (! $user) return;
        if ($user->is_admin) return;
        if ($origin->user_id !== $user->id) {
            abort(403, 'Forbidden');
        }
    }
}
