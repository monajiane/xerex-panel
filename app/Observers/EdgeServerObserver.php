<?php

namespace App\Observers;

use App\Events\EdgeServerStatusChanged;
use App\Models\EdgeServer;

class EdgeServerObserver
{
    public function updated(EdgeServer $edge): void
    {
        if ($edge->isDirty('status')) {
            EdgeServerStatusChanged::dispatch(
                $edge,
                (string) $edge->getOriginal('status'),
            );
        }
    }

    public function created(EdgeServer $edge): void
    {
        // New edges always start in PROVISIONING - emit an event for the dashboard.
        EdgeServerStatusChanged::dispatch($edge, 'new');
    }
}
