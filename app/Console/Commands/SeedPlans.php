<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Seed the default plan set: Free, Pro, Business, Enterprise.
 * Re-running this is safe; existing rows are updated in place and
 * their limits are replaced.
 */
class SeedPlans extends Command
{
    protected $signature = 'xerex:billing:seed-plans
        {--fresh : Drop all plans and their limits before seeding (DESTRUCTIVE)}';

    protected $description = 'Seed the default subscription plan catalog.';

    public function handle(BillingService $billing): int
    {
        if ($this->option('fresh')) {
            if (! $this->confirm('--fresh will delete all plans and limits. Continue?')) {
                $this->warn('Aborted.');
                return self::FAILURE;
            }
            \DB::table('plan_limits')->delete();
            \DB::table('plans')->delete();
            $this->info('Cleared existing plans and limits.');
        }

        $plans = $billing->seedDefaultPlans();
        $this->info("Seeded " . count($plans) . " plan(s):");
        foreach ($plans as $p) {
            $this->line(sprintf(
                "  - %-12s  %-10s  %s limit(s)",
                $p->slug,
                $p->formattedPrice(),
                $p->limits->count(),
            ));
        }
        return self::SUCCESS;
    }
}
