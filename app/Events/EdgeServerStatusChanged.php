<?php

namespace App\Events;

use App\Models\EdgeServer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever an edge server's status changes (online/offline/degraded/etc.).
 * Useful for the dashboard to update the edge fleet list in real time.
 */
class EdgeServerStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public EdgeServer $edge,
        public string $previousStatus,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard'),
            new PrivateChannel('edges.' . $this->edge->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'edge.status';
    }

    public function broadcastWith(): array
    {
        return [
            'edge_id'        => $this->edge->id,
            'edge_name'      => $this->edge->name,
            'previous_status'=> $this->previousStatus,
            'new_status'     => $this->edge->status,
            'last_seen_at'   => $this->edge->last_seen_at?->toIso8601String(),
            'at'             => now()->toIso8601String(),
        ];
    }
}
