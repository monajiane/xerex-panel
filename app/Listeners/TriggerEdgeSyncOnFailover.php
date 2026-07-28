<?php

namespace App\Listeners;

use App\Events\FailoverTriggered;
use App\Jobs\SyncEdgeConfig;
use App\Models\ProxyRule;
use Illuminate\Support\Facades\Log;

/**
 * When an origin is auto-disabled (or auto-recovered), all edges that serve
 * proxy rules pointing to that origin must re-push their config so nginx
 * upstreams reflect the new state. This listener queues one job per edge
 * instead of one per rule to keep the queue small.
 */
class TriggerEdgeSyncOnFailover
{
    public function handle(FailoverTriggered $event): void
    {
        $origin = $event->origin;

        $affectedEdges = ProxyRule::query()
            ->where('origin_server_id', $origin->id)
            ->pluck('edge_server_id')
            ->unique();

        if ($affectedEdges->isEmpty()) {
            Log::info("Failover for origin {$origin->name} ({$event->reason}) but no proxy rules use it");
            return;
        }

        Log::info("Failover for origin {$origin->name} ({$event->reason}) - syncing {$affectedEdges->count()} edges");

        foreach ($affectedEdges as $edgeId) {
            // Pick one representative rule per edge - the SyncEdgeConfig job
            // re-reads ALL enabled rules for the edge anyway.
            $rule = ProxyRule::where('edge_server_id', $edgeId)->first();
            if ($rule) {
                SyncEdgeConfig::dispatch($rule->id);
            }
        }
    }
}
