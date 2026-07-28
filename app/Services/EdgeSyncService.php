<?php

namespace App\Services;

use App\Jobs\SyncEdgeConfig;
use App\Jobs\RemoveEdgeConfig;
use App\Models\ProxyRule;

/**
 * EdgeSyncService - queues configuration sync jobs to edge agents.
 * The actual nginx config generation and reload happens in a queued job
 * to keep the controller responses fast.
 */
class EdgeSyncService
{
    public function queueSync(ProxyRule $rule): void
    {
        SyncEdgeConfig::dispatch($rule->id);
    }

    public function queueRemove(ProxyRule $rule): void
    {
        RemoveEdgeConfig::dispatch($rule->edge_server_id, $rule->id);
    }
}
