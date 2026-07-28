<?php

namespace App\Events;

use App\Models\ProxyRule;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a ProxyRule is created/updated/deleted/toggled. The frontend
 * subscribes to this to refresh the proxy rules list and to show a toast.
 */
class ProxyRuleUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_TOGGLED = 'toggled';

    public function __construct(
        public ?ProxyRule $rule,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('dashboard')];
        if ($this->rule && $this->rule->edge_server_id) {
            $channels[] = new PrivateChannel('edges.' . $this->rule->edge_server_id);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'proxyrule.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'rule'   => $this->rule ? [
                'id'           => $this->rule->id,
                'uuid'         => $this->rule->uuid,
                'domain_id'    => $this->rule->domain_id,
                'edge_id'      => $this->rule->edge_server_id,
                'origin_id'    => $this->rule->origin_server_id,
                'type'         => $this->rule->type,
                'enabled'      => $this->rule->enabled,
                'is_primary'   => $this->rule->is_primary,
            ] : null,
            'at'     => now()->toIso8601String(),
        ];
    }
}
