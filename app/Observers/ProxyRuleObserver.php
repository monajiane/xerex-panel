<?php

namespace App\Observers;

use App\Events\ProxyRuleUpdated;
use App\Models\ProxyRule;
use App\Services\EdgeSyncService;

class ProxyRuleObserver
{
    public function __construct(private readonly EdgeSyncService $sync) {}

    public function created(ProxyRule $rule): void
    {
        $this->sync->queueSync($rule);
        ProxyRuleUpdated::dispatch($rule, ProxyRuleUpdated::ACTION_CREATED);
    }

    public function updated(ProxyRule $rule): void
    {
        if ($rule->isDirty(['enabled', 'origin_server_id', 'edge_server_id', 'path', 'type', 'path_match_type', 'weight', 'priority'])) {
            $this->sync->queueSync($rule);
        }
        ProxyRuleUpdated::dispatch($rule, ProxyRuleUpdated::ACTION_UPDATED);
    }

    public function deleted(ProxyRule $rule): void
    {
        $this->sync->queueRemove($rule);
        ProxyRuleUpdated::dispatch(null, ProxyRuleUpdated::ACTION_DELETED);
    }
}
