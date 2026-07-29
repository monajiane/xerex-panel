<?php

namespace App\Console\Commands;

use App\Models\IpList;
use App\Services\Security\IpListService;
use Illuminate\Console\Command;

/**
 * Remove expired IP-list entries (the entries that have an `expires_at`
 * in the past). Should be run daily via the scheduler.
 *
 *   php artisan xerex:security:prune-expiry
 *   php artisan xerex:security:prune-expiry --dry-run
 */
class PruneIpListExpiry extends Command
{
    protected $signature = 'xerex:security:prune-expiry
                            {--dry-run : Report what would be removed without touching the table}';

    protected $description = 'Remove IP allow/block entries whose expires_at is in the past';

    public function handle(IpListService $service): int
    {
        $expired = IpList::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired IP-list entries.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would remove {$expired->count()} entries:");
            foreach ($expired as $e) {
                $this->line(" • {$e->cidr} ({$e->list_type}) — {$e->reason} — expired {$e->expires_at->diffForHumans()}");
            }
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($expired as $e) {
            $e->delete();
            $count++;
        }

        $service->flushCache();
        $this->info("Pruned {$count} expired IP-list entries.");
        return self::SUCCESS;
    }
}
