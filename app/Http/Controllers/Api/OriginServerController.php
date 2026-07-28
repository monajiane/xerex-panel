<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OriginServer;
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
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('host', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'host'         => ['required', 'string', 'max:255'],
            'port'         => ['required', 'integer', 'min:1', 'max:65535'],
            'protocol'     => ['required', Rule::in(['http', 'https', 'grpc', 'tcp'])],
            'upstream_type'=> ['nullable', Rule::in(['web', 'websocket', 'tcp', 'grpc', 'sse'])],
            'ssl_enabled'  => ['boolean'],
            'ssl_verify'   => ['boolean'],
            'weight'       => ['nullable', 'integer', 'min:1', 'max:1000'],
            'health_check_path' => ['nullable', 'string', 'max:255'],
            'headers'      => ['nullable', 'array'],
        ]);

        $data['user_id'] = $request->user()->id;

        $origin = OriginServer::create($data);

        return response()->json($origin, 201);
    }

    public function show(OriginServer $originServer): JsonResponse
    {
        $this->authorizeAccess(request()->user(), $originServer);
        return response()->json($originServer);
    }

    public function update(Request $request, OriginServer $originServer): JsonResponse
    {
        $this->authorizeAccess($request->user(), $originServer);

        $data = $request->validate([
            'name'         => ['sometimes', 'string', 'max:120'],
            'host'         => ['sometimes', 'string', 'max:255'],
            'port'         => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'protocol'     => ['sometimes', Rule::in(['http', 'https', 'grpc', 'tcp'])],
            'ssl_enabled'  => ['boolean'],
            'ssl_verify'   => ['boolean'],
            'weight'       => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active'    => ['boolean'],
            'headers'      => ['nullable', 'array'],
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
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($originServer->host, $originServer->port, $errno, $errstr, 5);

        $latency = (int) ((microtime(true) - $start) * 1000);

        if (! $sock) {
            $originServer->markHealthCheck(false);
            return response()->json([
                'success' => false,
                'error'   => $errstr,
                'latency_ms' => $latency,
            ], 502);
        }

        fclose($sock);
        $originServer->markHealthCheck(true);

        return response()->json([
            'success'    => true,
            'latency_ms' => $latency,
            'url'        => $originServer->getUpstreamUrl(),
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
