<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RateLimit;
use App\Services\Security\RateLimitRequest;
use App\Services\Security\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD + test endpoints for rate-limit policies.
 */
class RateLimitController extends Controller
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function index(Request $request): JsonResponse
    {
        $query = RateLimit::query();
        if ($type = $request->query('limit_type')) {
            $query->where('limit_type', $type);
        }
        if ($scope = $request->query('scope_type')) {
            $query->where('scope_type', $scope);
        }
        if ($active = $request->query('is_active')) {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }
        $rows = $query->orderBy('id')->limit(200)->get();
        return response()->json([
            'policies' => $rows->map(fn (RateLimit $p) => $this->serialize($p)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $policy = RateLimit::create($data);
        $this->limiter->flushCache();
        return response()->json(['policy' => $this->serialize($policy)], 201);
    }

    public function show(RateLimit $rateLimit): JsonResponse
    {
        return response()->json(['policy' => $this->serialize($rateLimit)]);
    }

    public function update(Request $request, RateLimit $rateLimit): JsonResponse
    {
        $data = $this->validatePayload($request, partial: true);
        $rateLimit->update($data);
        $this->limiter->flushCache();
        return response()->json(['policy' => $this->serialize($rateLimit->fresh())]);
    }

    public function destroy(RateLimit $rateLimit): JsonResponse
    {
        $rateLimit->delete();
        $this->limiter->flushCache();
        return response()->json(null, 204);
    }

    public function toggle(RateLimit $rateLimit): JsonResponse
    {
        $rateLimit->update(['is_active' => !$rateLimit->is_active]);
        $this->limiter->flushCache();
        return response()->json(['policy' => $this->serialize($rateLimit)]);
    }

    /**
     * Inspect the current counter for a (policy, key) without incrementing.
     */
    public function inspect(Request $request, RateLimit $rateLimit): JsonResponse
    {
        $data = $request->validate([
            'ip'      => 'sometimes|ip',
            'user_id' => 'sometimes|integer|min:1',
            'path'    => 'sometimes|string|max:512',
            'domain'  => 'sometimes|string|max:255',
        ]);
        $req = RateLimitRequest::fromArray($data);
        $result = $this->limiter->inspect(
            $req,
            $rateLimit->scope_type === RateLimit::SCOPE_GLOBAL ? null : $rateLimit->scope_type,
            $rateLimit->scope_id,
        );
        return response()->json($result->toArray());
    }

    /**
     * Reset a single policy counter for the current caller.
     */
    public function reset(Request $request, RateLimit $rateLimit): JsonResponse
    {
        $user = $request->user();
        $bucket = $rateLimit->limit_type === RateLimit::LIMIT_USER
            ? 'u:' . ($user?->id ?? 'anon')
            : ($user?->id ? "u:{$user->id}" : ($request->ip() ?? 'anon'));
        $this->limiter->reset($rateLimit->slug, $bucket);
        return response()->json(['reset' => true]);
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name'            => $required . '|string|max:120',
            'description'     => 'sometimes|nullable|string|max:500',
            'scope_type'      => $required . '|in:global,domain,edge,user',
            'scope_id'        => 'sometimes|nullable|integer|min:1',
            'limit_type'      => $required . '|in:ip,user,path,domain',
            'max_requests'    => $required . '|integer|min:1|max:10000000',
            'window_seconds'  => $required . '|integer|min:1|max:86400',
            'burst_multiplier'=> 'sometimes|numeric|min:1|max:10',
            'action'          => $required . '|in:block,challenge,throttle,log',
            'is_active'       => 'sometimes|boolean',
            'metadata'        => 'sometimes|nullable|array',
        ]);
    }

    private function serialize(RateLimit $p): array
    {
        return [
            'id'               => $p->id,
            'uuid'             => $p->uuid,
            'name'             => $p->name,
            'slug'             => $p->slug,
            'description'      => $p->description,
            'scope_type'       => $p->scope_type,
            'scope_id'         => $p->scope_id,
            'limit_type'       => $p->limit_type,
            'max_requests'     => $p->max_requests,
            'window_seconds'   => $p->window_seconds,
            'burst_multiplier' => (float) $p->burst_multiplier,
            'effective_max'    => $p->effectiveMax(),
            'action'           => $p->action,
            'is_active'        => $p->is_active,
            'metadata'         => $p->metadata,
            'created_at'       => $p->created_at?->toIso8601String(),
            'updated_at'       => $p->updated_at?->toIso8601String(),
        ];
    }
}
