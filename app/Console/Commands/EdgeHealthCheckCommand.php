<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use Illuminate\Console\Command;

/**
 * Periodic scheduled health check.
 *
 * Runs every minute (see routes/console.php) and delegates the actual probing
 * logic to HealthCheckService. The command is intentionally thin so the same
 * logic can also be triggered from the API (HealthCheckController@runNow)
 * and from tests.
 */
class EdgeHealthCheckCommand extends Command
{
    protected $signature = 'xerex:health:check
        {--target=all : Probe scope: all|origins|edges}
        {--sync : Run synchronously without queueing}';

    protected $description = 'Run scheduled health checks against active origins and edges';

    public function handle(HealthCheckService $service): int
    {
        $this->info('Starting scheduled health checks...');
        $start = microtime(true);

        $stats = $service->runScheduledChecks();

        $elapsedMs = (int) ((microtime(true) - $start) * 1000);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Origins probed',     $stats['origins']],
                ['Edges probed',       $stats['edges']],
                ['Failed checks',      $stats['failed']],
                ['Origins disabled',   $stats['disabled']],
                ['Origins re-enabled', $stats['reenabled']],
            ]
        );

        $this->info(sprintf(
            'Health check run completed in %dms (%d origins, %d edges).',
            $elapsedMs,
            $stats['origins'],
            $stats['edges']
        ));

        if ($stats['disabled'] > 0 || $stats['reenabled'] > 0) {
            $this->warn(sprintf(
                'Failover activity: %d origin(s) disabled, %d re-enabled.',
                $stats['disabled'],
                $stats['reenabled']
            ));
        }

        return self::SUCCESS;
    }
}
