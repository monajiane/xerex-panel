<?php

namespace App\Console\Commands;

use App\Models\TrafficLog;
use Illuminate\Console\Command;

class TrafficPurgeCommand extends Command
{
    protected $signature = 'xerex:traffic:purge {--days=}';
    protected $description = 'Purge traffic logs older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('xerex.traffic.retention_days', 30));
        $cutoff = now()->subDays($days);

        $deleted = TrafficLog::where('logged_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} traffic log rows older than {$days} days");

        return self::SUCCESS;
    }
}
