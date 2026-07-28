<?php

namespace App\Observers;

use App\Events\OriginHealthChanged;
use App\Models\OriginServer;

class OriginServerObserver
{
    public function updated(OriginServer $origin): void
    {
        // Fire the real-time event whenever health_status flips.
        if ($origin->isDirty('health_status')) {
            OriginHealthChanged::dispatch(
                $origin,
                $origin->getOriginal('health_status'),
                $origin->health_status,
            );
        }
    }
}
