<?php

namespace App\Events;

use App\Models\OriginServer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the HealthCheckService automatically disables or re-enables
 * an origin (auto-failover / auto-recovery).
 *
 * The reason field is one of: 'disabled', 'recovered'.
 * Listeners (e.g. TriggerEdgeSyncOnFailover) queue a SyncEdgeConfig job for
 * each ProxyRule using this origin so edges pick up the new upstream list.
 */
class FailoverTriggered implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public OriginServer $origin,
        public string $reason, // 'disabled' | 'recovered'
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard'),
            new PrivateChannel('origins.' . $this->origin->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'origin.failover';
    }

    public function broadcastWith(): array
    {
        return [
            'origin_id'   => $this->origin->id,
            'origin_name' => $this->origin->name,
            'reason'      => $this->reason,
            'is_active'   => $this->origin->is_active,
            'health_status' => $this->origin->health_status,
            'at'          => now()->toIso8601String(),
        ];
    }
}
