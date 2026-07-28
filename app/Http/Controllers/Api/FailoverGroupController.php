<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OriginServer;
use App\Services\FailoverGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * FailoverGroupController
 *
 * REST endpoints to inspect, reorder, and trigger failover groups.
 *
 *   GET    /api/failover-groups                       - list groups with summary
 *   GET    /api/failover-groups/{group}               - one group with members
 *   POST   /api/failover-groups                       - create a new group of origins
 *   POST   /api/failover-groups/{group}/promote       - promote a specific member
 *   POST   /api/failover-groups/{group}/reorder       - reorder priorities
 *   DELETE /api/failover-groups/{group}               - dissolve (clear failover_group)
 */
class FailoverGroupController extends Controller
{
    public function __construct(protected FailoverGroupService $svc) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->svc->summary(),
        ]);
    }

    public function show(string $group): JsonResponse
    {
        $members = OriginServer::where('failover_group', $group)
            ->orderBy('failover_priority')
            ->orderBy('name')
            ->get();

        if ($members->isEmpty()) {
            return response()->json(['message' => 'Group not found'], 404);
        }

        return response()->json([
            'group'   => $group,
            'members' => $members,
            'leader'  => $members->firstWhere('is_active', true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group'                       => 'required|string|max:64',
            'origins'                     => 'required|array|min:1',
            'origins.*.id'                => 'required|integer|exists:origin_servers,id',
            'origins.*.failover_priority' => 'required|integer|min:0',
        ]);

        foreach ($data['origins'] as $row) {
            OriginServer::where('id', $row['id'])->update([
                'failover_group'    => $data['group'],
                'failover_priority' => $row['failover_priority'],
            ]);
        }

        $this->reindex($data['group']);

        return response()->json([
            'group'   => $data['group'],
            'members' => OriginServer::where('failover_group', $data['group'])
                ->orderBy('failover_priority')
                ->get(),
        ], 201);
    }

    public function promote(Request $request, string $group): JsonResponse
    {
        $data = $request->validate([
            'origin_id' => 'required|integer|exists:origin_servers,id',
        ]);

        $origin = OriginServer::where('failover_group', $group)
            ->where('id', $data['origin_id'])
            ->firstOrFail();

        $newLeader = $this->svc->promoteReplacement(
            OriginServer::where('failover_group', $group)
                ->where('id', '!=', $origin->id)
                ->orderBy('failover_priority')
                ->first() ?? $origin,
        );

        return response()->json([
            'group'     => $group,
            'promoted'  => $newLeader,
        ]);
    }

    public function reorder(Request $request, string $group): JsonResponse
    {
        $data = $request->validate([
            'priorities'                       => 'required|array|min:1',
            'priorities.*.id'                  => 'required|integer',
            'priorities.*.failover_priority'   => 'required|integer|min:0',
        ]);

        $map = [];
        foreach ($data['priorities'] as $row) {
            $map[$row['id']] = $row['failover_priority'];
        }

        $updated = $this->svc->reorderGroup($group, $map);

        return response()->json([
            'group'   => $group,
            'updated' => $updated,
        ]);
    }

    public function destroy(string $group): JsonResponse
    {
        $count = OriginServer::where('failover_group', $group)
            ->update(['failover_group' => null]);

        return response()->json([
            'group'   => $group,
            'cleared' => $count,
        ]);
    }

    /**
     * After any membership change, make sure the lowest priority member is
     * the active one (single-leader invariant).
     */
    protected function reindex(string $group): void
    {
        $members = OriginServer::where('failover_group', $group)
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();

        $leader = $members->first();
        $priority = 0;
        foreach ($members as $member) {
            $member->failover_priority = $priority++;
            $member->is_active = ($member->id === $leader->id);
            $member->save();
        }
    }
}
