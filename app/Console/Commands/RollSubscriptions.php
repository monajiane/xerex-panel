<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Roll active subscriptions forward at period boundaries.
 * Also expires canceled subs whose current_period_end is in the past.
 *
 * Runs hourly.
 */
class RollSubscriptions extends Command
{
    protected $signature = 'xerex:billing:roll-subscriptions';

    protected $description = 'Roll active subscriptions forward at period boundaries; expire canceled ones.';

    public function handle(BillingService $billing): int
    {
        $rolled = 0;
        $expired = 0;
        $now = now();

        $subs = Subscription::whereIn('status', [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_TRIALING,
            Subscription::STATUS_CANCELED,
        ])->get();

        foreach ($subs as $sub) {
            $fresh = $billing->rollForward($sub);
            if ($fresh->status === Subscription::STATUS_EXPIRED) {
                $expired++;
            } else {
                $rolled++;
            }
        }

        $this->info("Rolled {$rolled} active sub(s); expired {$expired} canceled sub(s) at {$now->toIso8601String()}");
        return self::SUCCESS;
    }
}
