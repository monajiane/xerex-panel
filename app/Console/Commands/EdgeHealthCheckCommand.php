<?php

namespace App\Console\Commands;

use App\Models\EdgeServer;
use App\Models\OriginServer;
use Illuminate\Console\Command;

class EdgeHealthCheckCommand extends Command
{
    protected $signature = 'xerex:health:check';
    protected $description = 'Mark edge servers as offline if they have not checked in for 2 minutes';

    public function handle(): int
    {
        $staleThreshold = now()->subMinutes(2);

        $count = EdgeServer::where('status', EdgeServer::STATUS_ONLINE)
            ->where(function ($q) use ($staleThreshold) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $staleThreshold);
            })
            ->update(['status' => EdgeServer::STATUS_OFFLINE]);

        $this->info("Marked {$count} edge servers as offline");

        // Disable unhealthy origins (3+ consecutive failures)
        $origins = OriginServer::where('health_status', OriginServer::HEALTH_DOWN)
            ->where('consecutive_failures', '>=', config('xerex.health.fail_threshold', 3))
            ->where('is_active', true)
            ->get();

        foreach ($origins as $origin) {
            $origin->update(['is_active' => false]);
            $this->warn("Disabled origin {$origin->name} after repeated health failures");
        }

        return self::SUCCESS;
    }
}
