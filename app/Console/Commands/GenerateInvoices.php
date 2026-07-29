<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Generate invoices for active subscriptions that have crossed a
 * billing-period boundary since the last run.
 *
 * Runs daily. It is idempotent: we check that no invoice already exists
 * for a (subscription_id, period_start) pair before creating one.
 */
class GenerateInvoices extends Command
{
    protected $signature = 'xerex:billing:generate-invoices
        {--period= : Period start (Y-m-d). Defaults to first day of previous month.}';

    protected $description = 'Generate invoices for active subscriptions that are due billing.';

    public function handle(BillingService $billing): int
    {
        $period = $this->option('period')
            ? CarbonImmutable::parse($this->option('period'))->startOfMonth()
            : CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $periodEnd = $period->endOfMonth()->startOfHour();

        $count = 0;
        $subs = Subscription::whereIn('status', [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_TRIALING,
        ])->whereDate('created_at', '<=', $periodEnd)->get();

        foreach ($subs as $sub) {
            $exists = \App\Models\Invoice::where('subscription_id', $sub->id)
                ->where('period_start', $period)
                ->exists();
            if ($exists) continue;

            $inv = $billing->generateInvoice($sub, $period, $periodEnd, issue: true);
            $this->line("  - invoice {$inv->number} for sub {$sub->uuid} ({$inv->formattedTotal()})");
            $count++;
        }

        $this->info("Generated {$count} invoice(s) for period {$period->toDateString()} → {$periodEnd->toDateString()}");
        return self::SUCCESS;
    }
}
