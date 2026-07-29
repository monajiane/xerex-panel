<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plan / subscription / invoice lifecycle.
 *
 *  - listPlans() / findPlan()
 *  - subscribe($user, $plan, $trial?) -> Subscription
 *  - cancelAtPeriodEnd($user)
 *  - resumeCancellation($user)
 *  - generateMonthlyInvoice($subscription, $periodStart, $periodEnd) -> Invoice
 *  - markPaid($invoice, $paidAt)
 *
 * The invoice is generated from the plan's price and a single line item
 * "Xerex Panel - <Plan name> - <period>". Tax is left at 0; plug a real
 * tax engine here if/when needed.
 */
class BillingService
{
    public function __construct(private readonly QuotaService $quotas) {}

    /* -----------------------------------------------------------------
     | Plans
     * ----------------------------------------------------------------- */

    public function listPlans(bool $publicOnly = true)
    {
        $q = Plan::orderBy('sort_order')->orderBy('price_cents');
        if ($publicOnly) {
            $q->where('is_public', true)->where('is_active', true);
        }
        return $q->get();
    }

    public function findPlanBySlug(string $slug): ?Plan
    {
        return Plan::where('slug', $slug)->where('is_active', true)->first();
    }

    /**
     * Idempotently create the default plan set (Free, Pro, Business, Enterprise).
     * Re-running this updates prices/limits in place - safe to invoke from a seeder.
     */
    public function seedDefaultPlans(): array
    {
        $planDefs = [
            [
                'slug' => 'free', 'name' => 'Free', 'sort_order' => 10,
                'price_cents' => 0, 'trial_days' => 0, 'is_default' => true,
                'tagline' => 'Get started for free',
                'features' => ['support' => 'community', 'sla' => 'best-effort'],
                'limits' => [
                    ['metric' => 'domains',         'value' => 3,    'period' => 'lifetime'],
                    ['metric' => 'edges',           'value' => 1,    'period' => 'lifetime'],
                    ['metric' => 'origins',         'value' => 5,    'period' => 'lifetime'],
                    ['metric' => 'proxy_rules',     'value' => 10,   'period' => 'lifetime'],
                    ['metric' => 'ssl_certs',       'value' => 3,    'period' => 'lifetime'],
                    ['metric' => 'dns_zones',       'value' => 3,    'period' => 'lifetime'],
                    ['metric' => 'team_members',    'value' => 1,    'period' => 'lifetime'],
                    ['metric' => 'bandwidth_bytes', 'value' => 10737418240, 'period' => 'month'], // 10 GiB
                    ['metric' => 'requests',        'value' => 1000000, 'period' => 'month'],
                ],
            ],
            [
                'slug' => 'pro', 'name' => 'Pro', 'sort_order' => 20,
                'price_cents' => 1900, 'trial_days' => 14, 'is_default' => false,
                'tagline' => 'For production workloads',
                'features' => ['support' => 'email', 'sla' => '99.9%', 'custom_domains' => true],
                'limits' => [
                    ['metric' => 'domains',         'value' => 50,   'period' => 'lifetime'],
                    ['metric' => 'edges',           'value' => 5,    'period' => 'lifetime'],
                    ['metric' => 'origins',         'value' => 50,   'period' => 'lifetime'],
                    ['metric' => 'proxy_rules',     'value' => 200,  'period' => 'lifetime'],
                    ['metric' => 'ssl_certs',       'value' => 50,   'period' => 'lifetime'],
                    ['metric' => 'dns_zones',       'value' => 50,   'period' => 'lifetime'],
                    ['metric' => 'team_members',    'value' => 5,    'period' => 'lifetime'],
                    ['metric' => 'bandwidth_bytes', 'value' => 107374182400, 'period' => 'month'], // 100 GiB
                    ['metric' => 'requests',        'value' => 50000000, 'period' => 'month'],
                ],
            ],
            [
                'slug' => 'business', 'name' => 'Business', 'sort_order' => 30,
                'price_cents' => 4900, 'trial_days' => 14, 'is_default' => false,
                'tagline' => 'Scale with confidence',
                'features' => ['support' => 'priority', 'sla' => '99.95%', 'custom_domains' => true],
                'limits' => [
                    ['metric' => 'domains',         'value' => 250,  'period' => 'lifetime'],
                    ['metric' => 'edges',           'value' => 25,   'period' => 'lifetime'],
                    ['metric' => 'origins',         'value' => 250,  'period' => 'lifetime'],
                    ['metric' => 'proxy_rules',     'value' => 1000, 'period' => 'lifetime'],
                    ['metric' => 'ssl_certs',       'value' => 250,  'period' => 'lifetime'],
                    ['metric' => 'dns_zones',       'value' => 250,  'period' => 'lifetime'],
                    ['metric' => 'team_members',    'value' => 25,   'period' => 'lifetime'],
                    ['metric' => 'bandwidth_bytes', 'value' => 1073741824000, 'period' => 'month'], // 1 TiB
                    ['metric' => 'requests',        'value' => 500000000, 'period' => 'month'],
                ],
            ],
            [
                'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 40,
                'price_cents' => 19900, 'trial_days' => 30, 'is_default' => false,
                'tagline' => 'Custom contracts, dedicated support',
                'features' => ['support' => '24/7', 'sla' => '99.99%', 'custom_domains' => true, 'sso' => true],
                'limits' => [
                    ['metric' => 'domains',         'value' => -1, 'period' => 'lifetime'],
                    ['metric' => 'edges',           'value' => -1, 'period' => 'lifetime'],
                    ['metric' => 'origins',         'value' => -1, 'period' => 'lifetime'],
                    ['metric' => 'proxy_rules',     'value' => -1, 'period' => 'lifetime'],
                    ['metric' => 'ssl_certs',       'value' => -1, 'period' => 'lifetime'],
                    ['metric' => 'dns_zones',       'value' => -1, 'period' => 'lifetime'],
                    ['metric' => 'team_members',    'value' => -1, 'period' => 'lifetime'],
                    ['metric' => 'bandwidth_bytes', 'value' => -1, 'period' => 'month'],
                    ['metric' => 'requests',        'value' => -1, 'period' => 'month'],
                ],
            ],
        ];

        $created = [];
        DB::transaction(function () use ($planDefs, &$created) {
            foreach ($planDefs as $def) {
                $limits = $def['limits'];
                unset($def['limits']);

                // is_default is exclusive; clear others when we toggle one.
                if (! empty($def['is_default'])) {
                    Plan::where('is_default', true)->update(['is_default' => false]);
                }

                $plan = Plan::updateOrCreate(
                    ['slug' => $def['slug']],
                    $def + ['is_active' => true, 'is_public' => true, 'currency' => 'USD', 'billing_period' => 'month'],
                );

                // Replace limits for the plan (simple & idempotent).
                $plan->limits()->delete();
                foreach ($limits as $lim) {
                    $plan->limits()->create($lim);
                }
                $created[] = $plan->fresh('limits');
            }
        });

        return $created;
    }

    /* -----------------------------------------------------------------
     | Subscriptions
     * ----------------------------------------------------------------- */

    /**
     * Subscribe $user to $plan. If a subscription already exists, it is
     * ended and replaced (we still keep the old row for history).
     */
    public function subscribe(User $user, Plan $plan, bool $forceTrial = false): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $forceTrial) {
            // End any current subscription
            $current = $user->subscription()->first();
            if ($current) {
                $current->update([
                    'status'    => Subscription::STATUS_EXPIRED,
                    'ended_at'  => now(),
                ]);
            }

            $now = CarbonImmutable::now();
            $isTrial = $forceTrial && $plan->trial_days > 0;
            $trialEndsAt = $isTrial ? $now->addDays($plan->trial_days) : null;
            $periodEnd = $isTrial
                ? $trialEndsAt
                : $this->periodEndFor($plan, $now);

            return Subscription::create([
                'user_id'              => $user->id,
                'plan_id'              => $plan->id,
                'status'               => $isTrial ? Subscription::STATUS_TRIALING : Subscription::STATUS_ACTIVE,
                'trial_ends_at'        => $trialEndsAt,
                'starts_at'            => $now,
                'current_period_start' => $now,
                'current_period_end'   => $periodEnd,
                'cancel_at_period_end' => false,
            ]);
        });
    }

    public function cancelAtPeriodEnd(User $user): ?Subscription
    {
        $sub = $user->subscription()->first();
        if (! $sub) return null;
        $sub->update(['cancel_at_period_end' => true, 'canceled_at' => now()]);
        return $sub;
    }

    public function resumeCancellation(User $user): ?Subscription
    {
        $sub = $user->subscription()->first();
        if (! $sub) return null;
        $sub->update(['cancel_at_period_end' => false, 'canceled_at' => null]);
        return $sub;
    }

    /**
     * Roll a subscription forward into the next billing period, marking
     * the previous period as ended. Call this from a daily job to expire
     * canceled subs and to roll active subs forward at month boundaries.
     */
    public function rollForward(Subscription $sub): Subscription
    {
        if (! $sub->current_period_end) return $sub;

        $plan = $sub->plan;
        $now  = CarbonImmutable::now();

        // If we're past period end and the user canceled -> expire it.
        if ($sub->cancel_at_period_end && $sub->current_period_end->lt($now)) {
            $sub->update(['status' => Subscription::STATUS_EXPIRED, 'ended_at' => $now]);
            return $sub;
        }

        // If past period end and still active -> start a new period.
        if ($sub->current_period_end->lt($now)) {
            $newStart = $sub->current_period_end;
            $newEnd   = $this->periodEndFor($plan, $newStart);
            $sub->update([
                'current_period_start' => $newStart,
                'current_period_end'   => $newEnd,
            ]);
        }
        return $sub->fresh();
    }

    protected function periodEndFor(Plan $plan, CarbonImmutable $start): CarbonImmutable
    {
        return $plan->billing_period === 'year'
            ? $start->addYear()
            : $start->addMonth();
    }

    /* -----------------------------------------------------------------
     | Invoices
     * ----------------------------------------------------------------- */

    /**
     * Generate a draft/open invoice for the given subscription and period.
     * Does NOT issue the invoice - the caller is expected to call
     * ->issue() on the returned model when ready to bill.
     */
    public function generateInvoice(
        Subscription $sub,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        bool $issue = true,
    ): Invoice {
        $plan = $sub->plan;
        $lineItems = [[
            'description'  => "Xerex Panel - {$plan->name} plan",
            'quantity'     => 1,
            'unit_cents'   => $plan->price_cents,
            'amount_cents' => $plan->price_cents,
        ]];

        $subtotal = $plan->price_cents;
        $tax      = 0;
        $total    = $subtotal + $tax;

        $inv = Invoice::create([
            'user_id'         => $sub->user_id,
            'subscription_id' => $sub->id,
            'number'          => $this->nextInvoiceNumber(),
            'status'          => $issue ? Invoice::STATUS_OPEN : Invoice::STATUS_DRAFT,
            'currency'        => $plan->currency,
            'subtotal_cents'  => $subtotal,
            'tax_cents'       => $tax,
            'total_cents'     => $total,
            'line_items'      => $lineItems,
            'period_start'    => $periodStart,
            'period_end'      => $periodEnd,
            'issued_at'       => $issue ? now() : null,
            'due_at'          => $issue ? now()->addDays(7) : null,
        ]);

        return $inv;
    }

    public function markPaid(Invoice $invoice, ?CarbonImmutable $paidAt = null): Invoice
    {
        $invoice->update([
            'status'  => Invoice::STATUS_PAID,
            'paid_at' => $paidAt ?? CarbonImmutable::now(),
        ]);
        return $invoice;
    }

    public function void(Invoice $invoice): Invoice
    {
        $invoice->update(['status' => Invoice::STATUS_VOID]);
        return $invoice;
    }

    /* -----------------------------------------------------------------
     | Invoice numbering
     * ----------------------------------------------------------------- */

    /**
     * Allocate the next human-friendly invoice number: INV-YYYY-NNNNN.
     * Uses a DB-level lock so concurrent jobs do not collide.
     */
    protected function nextInvoiceNumber(): string
    {
        $year = date('Y');
        return DB::transaction(function () use ($year) {
            $count = Invoice::whereYear('created_at', $year)->lockForUpdate()->count();
            return sprintf('INV-%s-%05d', $year, $count + 1);
        });
    }
}
