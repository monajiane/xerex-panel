<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IpList;
use App\Services\Security\IpListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD + bulk-import endpoints for IP allow/block lists.
 */
class IpListController extends Controller
{
    public function __construct(private readonly IpListService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = IpList::query();
        if ($type = $request->query('list_type')) {
            $query->where('list_type', $type);
        }
        if ($active = $request->query('active')) {
            $query->active();
        }
        $rows = $query->orderByDesc('id')->limit(500)->get();
        return response()->json([
            'entries' => $rows->map(fn (IpList $e) => $this->serialize($e)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cidr'       => 'required|string|max:64',
            'list_type'  => 'required|in:allow,block',
            'reason'     => 'sometimes|nullable|string|max:500',
            'source'     => 'sometimes|nullable|string|max:64',
            'scope_type' => 'sometimes|nullable|in:global,domain,edge',
            'scope_id'   => 'sometimes|nullable|integer|min:1',
            'expires_at' => 'sometimes|nullable|date',
        ]);

        $normalised = $this->service->normaliseCidr($data['cidr']);
        if (!$normalised) {
            return response()->json(['error' => 'invalid_cidr', 'cidr' => $data['cidr']], 422);
        }
        $data['cidr'] = $normalised;
        $data['created_by'] = $request->user()?->id;
        if (empty($data['source'])) {
            $data['source'] = 'manual';
        }

        $entry = IpList::create($data);
        $this->service->flushCache();
        return response()->json(['entry' => $this->serialize($entry)], 201);
    }

    public function show(IpList $ipList): JsonResponse
    {
        return response()->json(['entry' => $this->serialize($ipList)]);
    }

    public function update(Request $request, IpList $ipList): JsonResponse
    {
        $data = $request->validate([
            'list_type'  => 'sometimes|in:allow,block',
            'reason'     => 'sometimes|nullable|string|max:500',
            'source'     => 'sometimes|nullable|string|max:64',
            'scope_type' => 'sometimes|nullable|in:global,domain,edge',
            'scope_id'   => 'sometimes|nullable|integer|min:1',
            'expires_at' => 'sometimes|nullable|date',
        ]);
        $ipList->update($data);
        $this->service->flushCache();
        return response()->json(['entry' => $this->serialize($ipList->fresh())]);
    }

    public function destroy(IpList $ipList): JsonResponse
    {
        $ipList->delete();
        $this->service->flushCache();
        return response()->json(null, 204);
    }

    /**
     * Bulk-import a list of CIDRs (one per line, or array).
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'list_type' => 'required|in:allow,block',
            'reason'    => 'sometimes|nullable|string|max:500',
            'source'    => 'sometimes|nullable|string|max:64',
            'cidrs'     => 'required',
        ]);

        $raw = $data['cidrs'];
        $cidrs = is_array($raw) ? $raw : preg_split('/\r?\n/', (string) $raw);
        $created = [];
        $skipped = [];

        foreach ($cidrs as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '' || str_starts_with($cidr, '#')) continue;

            $normalised = $this->service->normaliseCidr($cidr);
            if (!$normalised) {
                $skipped[] = $cidr;
                continue;
            }
            $entry = IpList::firstOrCreate(
                ['cidr' => $normalised, 'list_type' => $data['list_type']],
                [
                    'reason'   => $data['reason']   ?? null,
                    'source'   => $data['source']   ?? 'manual',
                    'created_by' => $request->user()?->id,
                ],
            );
            $created[] = $entry->cidr;
        }

        $this->service->flushCache();
        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
        ]);
    }

    /**
     * Check whether the supplied IP is on any active list.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ip' => 'required|ip',
        ]);
        $block = $this->service->isBlocked($data['ip']);
        $allow = $this->service->isAllowed($data['ip']);
        return response()->json([
            'ip'      => $data['ip'],
            'blocked' => $block !== null,
            'block'   => $block ? $this->serialize($block) : null,
            'allowed' => $allow,
        ]);
    }

    private function serialize(IpList $e): array
    {
        return [
            'id'         => $e->id,
            'uuid'       => $e->uuid,
            'cidr'       => $e->cidr,
            'list_type'  => $e->list_type,
            'reason'     => $e->reason,
            'source'     => $e->source,
            'scope_type' => $e->scope_type,
            'scope_id'   => $e->scope_id,
            'expires_at' => $e->expires_at?->toIso8601String(),
            'is_expired' => $e->isExpired(),
            'created_by' => $e->created_by,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
