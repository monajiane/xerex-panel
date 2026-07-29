<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(BillingService::class);
    }

    public function test_seed_default_plans_creates_four_plans(): void
    {
        $plans = $this->svc->seedDefaultPlans();
        $this->assertCount(4, $plans);
        $this->assertSame(['free', 'pro', 'business', 'enterprise'],
            collect($plans)->pluck('slug')->all());
        // exactly one default plan
        $this->assertSame(1, Plan::where('is_default', true)->count());
    }

    public function test_seed_is_idempotent(): void
    {
        $this->svc->seedDefaultPlans();
        $this->svc->seedDefaultPlans();
        $this->assertSame(4, Plan::count());
    }

    public function test_subscribe_creates_active_subscription(): void
    {
        $plan = Plan::factory()->pro()->create();
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan);

        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertNotNull($sub->current_period_end);
    }

    public function test_subscribe_with_trial_marks_trialing(): void
    {
        $plan = Plan::factory()->create(['trial_days' => 14]);
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan, forceTrial: true);

        $this->assertSame(Subscription::STATUS_TRIALING, $sub->status);
        $this->assertNotNull($sub->trial_ends_at);
    }

    public function test_subscribe_replaces_existing_subscription(): void
    {
        $pro = Plan::factory()->pro()->create();
        $biz = Plan::factory()->business()->create();
        $user = User::factory()->create();
        $this->svc->subscribe($user, $pro);
        $this->svc->subscribe($user, $biz);

        // 2 rows total: the first one is now expired, the second is active
        $subs = Subscription::where('user_id', $user->id)->get();
        $this->assertCount(2, $subs);
        $this->assertCount(1, $subs->where('status', Subscription::STATUS_EXPIRED));
        $this->assertCount(1, $subs->where('status', Subscription::STATUS_ACTIVE));
    }

    public function test_cancel_at_period_end(): void
    {
        $plan = Plan::factory()->create();
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan);
        $this->assertFalse($sub->cancel_at_period_end);

        $this->svc->cancelAtPeriodEnd($user);
        $sub->refresh();
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertNotNull($sub->canceled_at);
    }

    public function test_resume_clears_cancellation_flag(): void
    {
        $plan = Plan::factory()->create();
        $user = User::factory()->create();
        $this->svc->subscribe($user, $plan);
        $this->svc->cancelAtPeriodEnd($user);
        $this->svc->resumeCancellation($user);
        $sub = $user->subscription()->first();
        $this->assertFalse($sub->cancel_at_period_end);
    }

    public function test_roll_forward_expires_canceled_sub_past_period_end(): void
    {
        $plan = Plan::factory()->create();
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan);
        $this->svc->cancelAtPeriodEnd($user);
        // force the period end into the past
        $sub->update(['current_period_end' => now()->subDay()]);
        $this->svc->rollForward($sub->fresh());
        $this->assertSame(Subscription::STATUS_EXPIRED, $sub->fresh()->status);
    }

    public function test_roll_forward_advances_active_sub_past_period_end(): void
    {
        $plan = Plan::factory()->pro()->create(); // monthly
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan);
        $oldEnd = $sub->current_period_end;
        $sub->update(['current_period_end' => now()->subDay()]);
        $this->svc->rollForward($sub->fresh());
        $fresh = $sub->fresh();
        $this->assertTrue($fresh->current_period_end->gt($oldEnd));
    }

    public function test_generate_invoice_creates_row_with_correct_total(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 4900, 'currency' => 'USD']);
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan);

        $start = CarbonImmutable::now()->startOfMonth();
        $end   = $start->endOfMonth();
        $inv   = $this->svc->generateInvoice($sub, $start, $end, issue: true);

        $this->assertSame('open', $inv->status);
        $this->assertSame(4900, $inv->total_cents);
        $this->assertSame('USD',  $inv->currency);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $inv->number);
        $this->assertNotNull($inv->issued_at);
        $this->assertNotNull($inv->due_at);
    }

    public function test_generate_invoice_is_idempotent_per_period(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1000]);
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan);

        $start = CarbonImmutable::parse('2026-04-01');
        $end   = $start->endOfMonth();
        $this->svc->generateInvoice($sub, $start, $end);
        $this->svc->generateInvoice($sub, $start, $end);
        $this->assertSame(1, Invoice::where('subscription_id', $sub->id)->count());
    }

    public function test_mark_paid_sets_status_and_paid_at(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1000]);
        $user = User::factory()->create();
        $sub = $this->svc->subscribe($user, $plan);
        $inv = $this->svc->generateInvoice($sub, now()->startOfMonth(), now()->endOfMonth());

        $paid = $this->svc->markPaid($inv);
        $this->assertSame(Invoice::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->paid_at);
    }
}
