<?php

namespace App\Console\Commands;

use App\Models\TrafficLog;
use Illuminate\Console\Command;

/**
 * Prune old traffic logs. The rollup table retains long-term history; the
 * raw table is only useful for forensics and should be cut at ~30 days
 * by default.
 *
 *   php artisan traffic:prune --days=30 --batch=10000
 */
class PruneTraffic extends Command
{
    protected $signature = 'xerex:traffic:prune
        {--days=30 : Delete traffic_logs older than N days (rollups are kept)}
        {--batch=10000 : How many rows to delete per statement}';

    protected $description = 'Delete old traffic_logs (rollups are kept for long-term analytics).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $batch = max(100, (int) $this->option('batch'));
        $cutoff = now()->subDays($days);

        $this->info("Pruning traffic_logs older than {$cutoff->toIso8601String()} (older than {$days} days)");

        $deleted = 0;
        do {
            $count = TrafficLog::where('logged_at', '<', $cutoff)
                ->limit($batch)
                ->delete();
            $deleted += $count;
            $this->line("  deleted {$count} (running total: {$deleted})");
        } while ($count > 0);

        $this->info("Done. Total deleted: {$deleted}");
        return self::SUCCESS;
    }
}
