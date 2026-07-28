<?php

namespace App\Events;

use App\Models\SslCertificate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts when an SSL certificate is issued/renewed/revoked so the
 * dashboard can update its cert list in real time.
 */
class SslCertificateUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const ACTION_ISSUED  = 'issued';
    public const ACTION_RENEWED = 'renewed';
    public const ACTION_REVOKED = 'revoked';

    public function __construct(
        public ?SslCertificate $cert,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'ssl.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'cert'   => $this->cert ? [
                'id'         => $this->cert->id,
                'domain'     => $this->cert->domain,
                'state'      => $this->cert->state,
                'expires_at' => $this->cert->valid_to?->toIso8601String(),
            ] : null,
            'at'     => now()->toIso8601String(),
        ];
    }
}
