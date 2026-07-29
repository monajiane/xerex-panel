<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\BillingService;
use App\Services\QuotaService;
use App\Services\UsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public-facing billing API. All authenticated endpoints operate on the
 * current user unless the caller is an admin (in which case some accept
 * a `user_id` to act on behalf of).
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly QuotaService $quotas,
        private readonly UsageMeter $meter,
    ) {}

    /* -----------------------------------------------------------------
     | Plans
     * ----------------------------------------------------------------- */

    public function plans(Request $request): JsonResponse
    {
        $publicOnly = ! $request->user()?->is_admin;
        $plans = $this->billing->listPlans($publicOnly);
        return response()->json([
            'plans' => $plans->map(fn (Plan $p) => [
                'id'              => $p->id,
                'uuid'            => $p->uuid,
                'slug'            => $p->slug,
                'name'            => $p->name,
                'tagline'         => $p->tagline,
                'description'     => $p->description,
                'price_cents'     => $p->price_cents,
                'currency'        => $p->currency,
                'billing_period'  => $p->billing_period,
                'trial_days'      => $p->trial_days,
                'formatted_price' => $p->formattedPrice(),
                'is_default'      => $p->is_default,
                'sort_order'      => $p->sort_order,
                'features'        => $p->features,
                'limits'          => $p->limits->map(fn ($l) => [
                    'metric' => $l->metric,
                    'value'  => $l->value,
                    'period' => $l->period,
                    'unlimited' => $l->isUnlimited(),
                ]),
            ]),
        ]);
    }

    /* -----------------------------------------------------------------
     | Subscription (current user)
     * ----------------------------------------------------------------- */

    public function showSubscription(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub  = $user->subscription()->with('plan.limits')->first();
        $plan = $this->quotas->resolvePlan($user);

        return response()->json([
            'subscription' => $sub ? [
                'uuid'                  => $sub->uuid,
                'plan'                  => $sub->plan ? [
                    'id'    => $sub->plan->id,
                    'slug'  => $sub->plan->slug,
                    'name'  => $sub->plan->name,
                ] : null,
                'status'                => $sub->status,
                'is_trialing'           => $sub->isTrialing(),
                'cancel_at_period_end'  => $sub->cancel_at_period_end,
                'current_period_start'  => $sub->current_period_start?->toIso8601String(),
                'current_period_end'    => $sub->current_period_end?->toIso8601String(),
                'trial_ends_at'         => $sub->trial_ends_at?->toIso8601String(),
                'started_at'            => $sub->starts_at?->toIso8601String(),
            ] : null,
            'effective_plan' => $plan ? [
                'id'    => $plan->id,
                'slug'  => $plan->slug,
                'name'  => $plan->name,
            ] : null,
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug'  => 'required|string|max:64',
            'with_trial' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        $plan = $this->billing->findPlanBySlug($data['plan_slug']);
        if (! $plan) {
            return response()->json(['error' => 'plan_not_found'], 404);
        }
        $sub = $this->billing->subscribe(
            $user,
            $plan,
            forceTrial: (bool) ($data['with_trial'] ?? false),
        );
        return response()->json([
            'subscription' => [
                'uuid' => $sub->uuid,
                'status' => $sub->status,
                'plan_slug' => $plan->slug,
                'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
                'current_period_end' => $sub->current_period_end?->toIso8601String(),
            ],
        ]);
    }

    public function cancelSubscription(Request $request): JsonResponse
    {
        $sub = $this->billing->cancelAtPeriodEnd($request->user());
        if (! $sub) return response()->json(['error' => 'no_active_subscription'], 404);
        return response()->json([
            'subscription' => [
                'uuid' => $sub->uuid,
                'cancel_at_period_end' => true,
                'current_period_end'   => $sub->current_period_end?->toIso8601String(),
            ],
        ]);
    }

    public function resumeSubscription(Request $request): JsonResponse
    {
        $sub = $this->billing->resumeCancellation($request->user());
        if (! $sub) return response()->json(['error' => 'no_active_subscription'], 404);
        return response()->json([
            'subscription' => [
                'uuid' => $sub->uuid,
                'cancel_at_period_end' => false,
            ],
        ]);
    }

    /* -----------------------------------------------------------------
     | Quotas
     * ----------------------------------------------------------------- */

    public function quotas(Request $request): JsonResponse
    {
        $user  = $request->user();
        $plan  = $this->quotas->resolvePlan($user);
        $snap  = $this->quotas->snapshot($user);
        return response()->json([
            'plan'    => $plan ? [
                'id'   => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'is_default' => $plan->is_default,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'formatted_price' => $plan->formattedPrice(),
            ] : null,
            'metrics' => $snap,
        ]);
    }

    /* -----------------------------------------------------------------
     | Invoices
     * ----------------------------------------------------------------- */

    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Invoice::where('user_id', $user->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $rows = $q->limit(100)->get();
        return response()->json([
            'invoices' => $rows->map(fn (Invoice $i) => $this->serializeInvoice($i)),
        ]);
    }

    public function showInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeOwnership($request, $invoice);
        return response()->json(['invoice' => $this->serializeInvoice($invoice)]);
    }

    public function payInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeOwnership($request, $invoice);
        if ($invoice->isPaid()) {
            return response()->json(['error' => 'already_paid'], 409);
        }
        $invoice = $this->billing->markPaid($invoice);
        return response()->json(['invoice' => $this->serializeInvoice($invoice)]);
    }

    /* -----------------------------------------------------------------
     | Admin helpers
     * ----------------------------------------------------------------- */

    /** Admin: reseed the default plan set. */
    public function seedPlans(Request $request): JsonResponse
    {
        if (! $request->user()?->is_admin) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $plans = $this->billing->seedDefaultPlans();
        return response()->json([
            'seeded' => count($plans),
            'plans'  => collect($plans)->map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name]),
        ]);
    }

    protected function authorizeOwnership(Request $request, Invoice $invoice): void
    {
        $user = $request->user();
        if (! $user) abort(401);
        if (! $user->is_admin && $invoice->user_id !== $user->id) {
            abort(403, 'not your invoice');
        }
    }

    protected function serializeInvoice(Invoice $i): array
    {
        return [
            'uuid'            => $i->uuid,
            'number'          => $i->number,
            'status'          => $i->status,
            'is_overdue'      => $i->isOverdue(),
            'currency'        => $i->currency,
            'subtotal_cents'  => $i->subtotal_cents,
            'tax_cents'       => $i->tax_cents,
            'total_cents'     => $i->total_cents,
            'formatted_total' => $i->formattedTotal(),
            'line_items'      => $i->line_items,
            'period_start'    => $i->period_start?->toIso8601String(),
            'period_end'      => $i->period_end?->toIso8601String(),
            'issued_at'       => $i->issued_at?->toIso8601String(),
            'due_at'          => $i->due_at?->toIso8601String(),
            'paid_at'         => $i->paid_at?->toIso8601String(),
        ];
    }
}
