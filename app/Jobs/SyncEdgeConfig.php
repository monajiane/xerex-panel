<?php

namespace App\Jobs;

use App\Models\ProxyRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncEdgeConfig implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public int $proxyRuleId) {}

    public function handle(): void
    {
        $rule = ProxyRule::with(['domain', 'edgeServer', 'originServer'])->find($this->proxyRuleId);
        if (! $rule) return;

        // Generate nginx config for this rule
        $generator = app(\App\Services\NginxConfigGenerator::class);
        $config = $generator->generate($rule);
        $hash = hash('sha256', $config);

        if ($rule->config_hash === $hash) {
            return; // No change
        }

        $rule->update([
            'nginx_config'         => $config,
            'config_hash'          => $hash,
            'config_generated_at'  => now(),
        ]);

        // Send config to edge agent
        try {
            \Illuminate\Support\Facades\Http::withToken($rule->edgeServer->agent_token)
                ->timeout(5)
                ->post($this->agentUrl($rule->edgeServer, '/api/internal/config-sync'), [
                    'rule_id'  => $rule->id,
                    'config'   => $config,
                    'hash'     => $hash,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to push config to edge', [
                'edge_id' => $rule->edge_server_id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function agentUrl($edge, string $path): string
    {
        $scheme = $edge->agent_tls_enabled ? 'https' : 'http';
        $port = 8443;
        return sprintf('%s://%s:%d%s', $scheme, $edge->ip_address, $port, $path);
    }
}
