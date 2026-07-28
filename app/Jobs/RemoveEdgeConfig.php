<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RemoveEdgeConfig implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $edgeServerId,
        public int $proxyRuleId,
    ) {}

    public function handle(): void
    {
        $edge = \App\Models\EdgeServer::find($this->edgeServerId);
        if (! $edge) return;

        try {
            Http::withToken($edge->agent_token)
                ->timeout(5)
                ->post(sprintf('https://%s:8443/api/internal/config-remove', $edge->ip_address), [
                    'rule_id' => $this->proxyRuleId,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove config from edge', [
                'edge_id' => $this->edgeServerId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
