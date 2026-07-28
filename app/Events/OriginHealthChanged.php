<?php

namespace App\Events;

use App\Models\OriginServer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever an origin server transitions between UP and DOWN health states.
 * Subscribers (UI dashboards, alerting) can react to flips to/from "healthy".
 */
class OriginHealthChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public OriginServer $origin,
        public string $previousStatus,
        public string $newStatus,
    ) {}

    /**
     * Broadcast on a private channel so only authenticated users can subscribe.
     * The dashboard Vue frontend listens to "origins.{id}" for live updates.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('origins.' . $this->origin->id),
            new PrivateChannel('dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'origin.health.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'origin_id'       => $this->origin->id,
            'origin_name'     => $this->origin->name,
            'previous_status' => $this->previousStatus,
            'new_status'      => $this->newStatus,
            'is_active'       => $this->origin->is_active,
            'consecutive_failures' => $this->origin->consecutive_failures,
            'at'              => now()->toIso8601String(),
        ];
    }
}
