<?php

namespace App\Services;

use App\Events\FailoverTriggered;
use App\Models\OriginServer;
use App\Models\ProxyRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * FailoverGroupService
 *
 * Implements active/passive failover groups: each group has one "primary"
 * (the lowest-priority active, healthy member). When the primary goes DOWN
 * for a sustained period (controlled by the health check service), we:
 *
 *   1. Pick the next-best candidate (next priority that is active+healthy).
 *   2. Lower the primary's `failover_priority` so it loses leader status.
 *   3. Raise the candidate's priority so it becomes the new leader.
 *   4. Dispatch SyncEdgeConfig jobs so edges reload their upstreams.
 *
 * This service is called from HealthCheckService.applyOriginState() once
 * the fail threshold is exceeded, and also from a manual admin endpoint.
 */
class FailoverGroupService
{
    public function __construct() {}

    /**
     * Try to promote a replacement for a failed origin within its group.
     * Returns the promoted OriginServer, or null if no healthy candidate
     * is available.
     */
    public function promoteReplacement(OriginServer $failed): ?OriginServer
    {
        if (! $failed->failover_group) {
            return null;
        }

        $candidate = $this->pickNextHealthy($failed->failover_group, exclude: $failed->id);
        if (! $candidate) {
            Log::warning("No healthy candidate for failover group {$failed->failover_group}", [
                'failed_origin' => $failed->id,
            ]);
            return null;
        }

        // Make the candidate the new leader: give it the lowest priority in the group.
        $lowestPriority = OriginServer::where('failover_group', $failed->failover_group)
            ->min('failover_priority') ?? 0;

        $failed->failover_priority = $lowestPriority + 1;
        $failed->save();

        $candidate->failover_priority = $lowestPriority - 1;
        $candidate->is_active = true;
        $candidate->save();

        Log::info("Promoted origin {$candidate->name} (id={$candidate->id}) in group {$failed->failover_group}", [
            'previous_leader' => $failed->id,
        ]);

        event(new FailoverTriggered($candidate, 'promoted'));

        $this->syncAffectedEdges($failed);
        $this->syncAffectedEdges($candidate);

        return $candidate;
    }

    /**
     * Demote a member (admin action).
     */
    public function demote(OriginServer $origin): void
    {
        $origin->failover_priority = $origin->failover_priority + 100;
        $origin->save();
        $this->syncAffectedEdges($origin);
    }

    /**
     * Reorder an entire group based on a priority map [origin_id => priority].
     */
    public function reorderGroup(string $group, array $priorityMap): int
    {
        $count = 0;
        foreach ($priorityMap as $originId => $priority) {
            $updated = OriginServer::where('failover_group', $group)
                ->where('id', $originId)
                ->update(['failover_priority' => (int) $priority]);
            $count += $updated;
        }

        // Re-sync every edge that uses any member of the group.
        $originIds = OriginServer::where('failover_group', $group)->pluck('id');
        $edgeIds = ProxyRule::whereIn('origin_server_id', $originIds)
            ->pluck('edge_server_id')
            ->unique();
        foreach ($edgeIds as $edgeId) {
            $rule = ProxyRule::where('edge_server_id', $edgeId)->first();
            if ($rule) {
                \App\Jobs\SyncEdgeConfig::dispatch($rule->id);
            }
        }

        return $count;
    }

    /**
     * List all failover groups with summary statistics.
     *
     * @return Collection<int, array{group:string, members:int, healthy:int, leader:?OriginServer}>
     */
    public function summary(): Collection
    {
        $groups = OriginServer::whereNotNull('failover_group')
            ->select('failover_group')
            ->distinct()
            ->pluck('failover_group');

        return $groups->map(function (string $group) {
            $members = OriginServer::where('failover_group', $group)
                ->orderedForFailover()
                ->get();
            $healthy = $members->where('is_active', true)
                ->where('health_status', OriginServer::HEALTH_UP)
                ->count();
            $leader = $members->firstWhere('is_active', true);

            return [
                'group'   => $group,
                'members' => $members->count(),
                'healthy' => $healthy,
                'leader'  => $leader,
                'list'    => $members,
            ];
        })->values();
    }

    /**
     * Pick the best healthy candidate in a group, excluding the given id.
     * "Best" = lowest failover_priority, active, healthy.
     */
    public function pickNextHealthy(string $group, ?int $exclude = null): ?OriginServer
    {
        return OriginServer::query()
            ->where('failover_group', $group)
            ->when($exclude, fn ($q) => $q->where('id', '!=', $exclude))
            ->active()
            ->where('health_status', OriginServer::HEALTH_UP)
            ->orderedForFailover()
            ->first();
    }

    /**
     * Queue a SyncEdgeConfig job for every edge that has rules pointing
     * at the given origin (so nginx upstreams pick up the new leader).
     */
    protected function syncAffectedEdges(OriginServer $origin): void
    {
        $edgeIds = ProxyRule::where('origin_server_id', $origin->id)
            ->pluck('edge_server_id')
            ->unique();

        foreach ($edgeIds as $edgeId) {
            $rule = ProxyRule::where('edge_server_id', $edgeId)->first();
            if ($rule) {
                \App\Jobs\SyncEdgeConfig::dispatch($rule->id);
            }
        }
    }
}
