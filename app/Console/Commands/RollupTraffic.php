<?php

namespace App\Console\Commands;

use App\Services\TrafficAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Aggregate the previous hour's traffic_logs into traffic_rollups.
 *
 * Default: every minute we update the previous full hour (so we are
 * catching up if the previous hour is no longer being written to).
 * The full day is also rebuilt at 00:05 to fix any late arrivals.
 *
 * Schedule:
 *   $schedule->command('traffic:rollup')->everyMinute()->withoutOverlapping();
 *   $schedule->command('traffic:rollup --full-day')->dailyAt('00:05');
 */
class RollupTraffic extends Command
{
    protected $signature = 'xerex:traffic:rollup
        {--from= : ISO timestamp, start of the window (default: previous hour start)}
        {--to=   : ISO timestamp, end of the window (default: previous hour end)}
        {--full-day : recompute the entire previous day (24 hour buckets)}';

    protected $description = 'Aggregate traffic_logs into hourly traffic_rollups.';

    public function handle(TrafficAggregator $aggregator): int
    {
        if ($this->option('full-day')) {
            $from = CarbonImmutable::yesterday()->startOfDay();
            $to   = $from->endOfDay()->startOfHour();
            $this->info("Rebuilding 24 buckets from {$from->toIso8601String()} to {$to->toIso8601String()}");
        } else {
            $from = $this->option('from')
                ? CarbonImmutable::parse($this->option('from'))->startOfHour()
                : CarbonImmutable::now()->subHour()->startOfHour();
            $to = $this->option('to')
                ? CarbonImmutable::parse($this->option('to'))->startOfHour()
                : $from;
        }

        $count = $aggregator->rebuildRange($from, $to);
        $this->info("Wrote {$count} rollup row(s) for {$from->toIso8601String()} → {$to->toIso8601String()}");
        return self::SUCCESS;
    }
}
