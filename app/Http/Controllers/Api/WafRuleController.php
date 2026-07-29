<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WafRule;
use App\Services\Security\WafEngine;
use App\Services\Security\WafRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD + test endpoints for WAF rules.
 */
class WafRuleController extends Controller
{
    public function __construct(private readonly WafEngine $waf) {}

    public function index(Request $request): JsonResponse
    {
        $query = WafRule::query();
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($active = $request->query('is_active')) {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }
        $rules = $query->orderByDesc('priority')->limit(200)->get();
        return response()->json([
            'rules' => $rules->map(fn (WafRule $r) => $this->serialize($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $data['pattern'] = $this->resolvePattern($data);

        $rule = WafRule::create($data);
        $this->waf->flushCache();
        return response()->json(['rule' => $this->serialize($rule)], 201);
    }

    public function show(WafRule $wafRule): JsonResponse
    {
        return response()->json(['rule' => $this->serialize($wafRule)]);
    }

    public function update(Request $request, WafRule $wafRule): JsonResponse
    {
        $data = $this->validatePayload($request, partial: true);
        if (isset($data['type']) && ($data['type'] !== WafRule::TYPE_REGEX)) {
            $data['pattern'] = WafRule::presetPatterns()[$data['type']] ?? $data['pattern'] ?? '';
        }
        $wafRule->update($data);
        $this->waf->flushCache();
        return response()->json(['rule' => $this->serialize($wafRule->fresh())]);
    }

    public function destroy(WafRule $wafRule): JsonResponse
    {
        $wafRule->delete();
        $this->waf->flushCache();
        return response()->json(null, 204);
    }

    public function toggle(WafRule $wafRule): JsonResponse
    {
        $wafRule->update(['is_active' => !$wafRule->is_active]);
        $this->waf->flushCache();
        return response()->json(['rule' => $this->serialize($wafRule)]);
    }

    /**
     * Test a synthetic request against the active rule set.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method'     => 'sometimes|string|max:16',
            'uri'        => 'required|string|max:2048',
            'query'      => 'sometimes|string',
            'body'       => 'sometimes|string',
            'user_agent' => 'sometimes|string|max:512',
            'headers'    => 'sometimes|array',
            'client_ip'  => 'sometimes|ip',
            'scope_type' => 'sometimes|string|in:global,domain,edge',
            'scope_id'   => 'sometimes|integer|min:1',
        ]);

        $wafRequest = WafRequest::fromArray($data);
        $matches = $this->waf->evaluateAll(
            $wafRequest,
            $data['scope_type'] ?? null,
            $data['scope_id']   ?? null,
        );

        return response()->json([
            'matches' => array_map(fn (array $m) => [
                'rule'     => $this->serialize($m['rule']),
                'evidence' => $m['evidence'],
            ], $matches),
            'request' => $wafRequest->toArray(),
        ]);
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name'         => $required . '|string|max:120',
            'description'  => 'sometimes|nullable|string',
            'type'         => $required . '|string|in:' . implode(',', array_keys(WafRule::presetPatterns() + [WafRule::TYPE_REGEX => 1, WafRule::TYPE_CUSTOM => 1])),
            'pattern'      => 'sometimes|nullable|string|max:4096',
            'target'       => $required . '|string|in:' . implode(',', [WafRule::TARGET_URI, WafRule::TARGET_QUERY, WafRule::TARGET_BODY, WafRule::TARGET_HEADER, WafRule::TARGET_USER_AGENT, WafRule::TARGET_ANY]),
            'target_field' => 'sometimes|nullable|string|max:64',
            'action'       => $required . '|string|in:' . implode(',', [WafRule::ACTION_ALLOW, WafRule::ACTION_BLOCK, WafRule::ACTION_CHALLENGE, WafRule::ACTION_LOG, WafRule::ACTION_RATE_LIMIT]),
            'priority'     => 'sometimes|integer|min:0|max:10000',
            'is_active'    => 'sometimes|boolean',
            'scope_type'   => 'sometimes|nullable|string|in:' . implode(',', [WafRule::SCOPE_GLOBAL, WafRule::SCOPE_DOMAIN, WafRule::SCOPE_EDGE]),
            'scope_id'     => 'sometimes|nullable|integer|min:1',
            'metadata'     => 'sometimes|nullable|array',
        ]);
    }

    private function resolvePattern(array $data): string
    {
        if (!empty($data['pattern'])) {
            return $data['pattern'];
        }
        if ($data['type'] === WafRule::TYPE_REGEX || $data['type'] === WafRule::TYPE_CUSTOM) {
            return ''; // caller must provide
        }
        return WafRule::presetPatterns()[$data['type']] ?? '';
    }

    private function serialize(WafRule $r): array
    {
        return [
            'id'           => $r->id,
            'uuid'         => $r->uuid,
            'name'         => $r->name,
            'slug'         => $r->slug,
            'description'  => $r->description,
            'type'         => $r->type,
            'pattern'      => $r->pattern,
            'target'       => $r->target,
            'target_field' => $r->target_field,
            'action'       => $r->action,
            'priority'     => $r->priority,
            'is_active'    => $r->is_active,
            'scope_type'   => $r->scope_type,
            'scope_id'     => $r->scope_id,
            'metadata'     => $r->metadata,
            'created_at'   => $r->created_at?->toIso8601String(),
            'updated_at'   => $r->updated_at?->toIso8601String(),
        ];
    }
}
