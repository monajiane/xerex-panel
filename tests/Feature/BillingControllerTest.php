<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /* -----------------------------------------------------------------
     | Plans
     * ----------------------------------------------------------------- */

    public function test_plans_endpoint_is_public(): void
    {
        Plan::factory()->free()->create();
        Plan::factory()->inactive()->create();

        $res = $this->getJson('/api/billing/plans');
        $res->assertOk()
            ->assertJsonCount('plans', 1); // inactive hidden
    }

    public function test_admin_sees_inactive_plans_too(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->free()->create();
        Plan::factory()->inactive()->create();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/billing/plans');
        $res->assertOk()->assertJsonCount('plans', 2);
    }

    /* -----------------------------------------------------------------
     | Subscription
     * ----------------------------------------------------------------- */

    public function test_show_subscription_for_user_without_one_returns_null(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/billing/subscription');
        $res->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('effective_plan.slug', null);
    }

    public function test_show_subscription_returns_current_sub(): void
    {
        $plan = Plan::factory()->pro()->create();
        $sub = Subscription::factory()->create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'status'  => Subscription::STATUS_ACTIVE,
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/billing/subscription');
        $res->assertOk()
            ->assertJsonPath('subscription.uuid', $sub->uuid)
            ->assertJsonPath('effective_plan.slug', 'pro');
    }

    public function test_subscribe_creates_a_subscription(): void
    {
        $plan = Plan::factory()->pro()->create();
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/billing/subscription', ['plan_slug' => $plan->slug]);
        $res->assertOk()
            ->assertJsonPath('subscription.plan_slug', $plan->slug)
            ->assertJsonPath('subscription.status', Subscription::STATUS_ACTIVE);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_subscribe_with_unknown_plan_returns_404(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/billing/subscription', ['plan_slug' => 'nope']);
        $res->assertNotFound()->assertJsonPath('error', 'plan_not_found');
    }

    public function test_subscribe_validation_requires_plan_slug(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/billing/subscription', []);
        $res->assertStatus(422);
    }

    public function test_cancel_then_resume(): void
    {
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
        ]);

        $cancel = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/billing/subscription/cancel');
        $cancel->assertOk()->assertJsonPath('subscription.cancel_at_period_end', true);

        $resume = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/billing/subscription/resume');
        $resume->assertOk()->assertJsonPath('subscription.cancel_at_period_end', false);
    }

    public function test_cancel_without_subscription_returns_404(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/billing/subscription/cancel');
        $res->assertNotFound();
    }

    /* -----------------------------------------------------------------
     | Quotas
     * ----------------------------------------------------------------- */

    public function test_quotas_endpoint_returns_plan_and_metrics(): void
    {
        $plan = Plan::factory()->free()->create();
        $plan->limits()->createMany([
            ['metric' => 'domains', 'value' => 3, 'period' => 'lifetime'],
            ['metric' => 'edges',   'value' => -1, 'period' => 'lifetime'],
        ]);
        Subscription::factory()->create([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/billing/quotas');
        $res->assertOk()
            ->assertJsonPath('plan.slug', 'free')
            ->assertJsonCount('metrics', 2);
    }

    /* -----------------------------------------------------------------
     | Invoices
     * ----------------------------------------------------------------- */

    public function test_invoices_lists_only_user_invoices(): void
    {
        $other = User::factory()->create();
        $plan = Plan::factory()->create(['price_cents' => 1000]);

        // 2 invoices for the current user, 1 for another user
        $svc = app(BillingService::class);
        $sub = $svc->subscribe($this->user, $plan);
        $svc->generateInvoice($sub, now()->startOfMonth(), now()->endOfMonth());
        $svc->generateInvoice($sub, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth());

        $otherSub = $svc->subscribe($other, $plan);
        $svc->generateInvoice($otherSub, now()->startOfMonth(), now()->endOfMonth());

        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/billing/invoices');
        $res->assertOk()->assertJsonCount('invoices', 2);
    }

    public function test_invoices_filters_by_status(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1000]);
        $svc  = app(BillingService::class);
        $sub  = $svc->subscribe($this->user, $plan);
        $open = $svc->generateInvoice($sub, now()->startOfMonth(), now()->endOfMonth());
        $paid = $svc->generateInvoice($sub, now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth());
        $svc->markPaid($paid);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/billing/invoices?status=paid');
        $res->assertOk()->assertJsonCount('invoices', 1);
    }

    public function test_show_invoice_for_another_user_is_403(): void
    {
        $other = User::factory()->create();
        $inv = Invoice::create([
            'user_id' => $other->id, 'number' => 'INV-2026-00001', 'status' => 'open',
            'currency' => 'USD', 'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000,
            'line_items' => [], 'period_start' => now(), 'period_end' => now()->endOfMonth(),
        ]);
        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/billing/invoices/{$inv->uuid}");
        $res->assertStatus(403);
    }

    public function test_pay_invoice_marks_paid(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1000]);
        $svc  = app(BillingService::class);
        $sub  = $svc->subscribe($this->user, $plan);
        $inv  = $svc->generateInvoice($sub, now()->startOfMonth(), now()->endOfMonth());

        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/billing/invoices/{$inv->uuid}/pay");
        $res->assertOk()->assertJsonPath('invoice.status', Invoice::STATUS_PAID);
        $this->assertNotNull($inv->fresh()->paid_at);
    }

    public function test_pay_already_paid_invoice_is_409(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1000]);
        $svc  = app(BillingService::class);
        $sub  = $svc->subscribe($this->user, $plan);
        $inv  = $svc->generateInvoice($sub, now()->startOfMonth(), now()->endOfMonth());
        $svc->markPaid($inv);

        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/billing/invoices/{$inv->uuid}/pay");
        $res->assertStatus(409);
    }

    /* -----------------------------------------------------------------
     | Admin
     * ----------------------------------------------------------------- */

    public function test_seed_plans_requires_admin(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/billing/plans/seed');
        $res->assertStatus(403);
    }

    public function test_seed_plans_works_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/billing/plans/seed');
        $res->assertOk()
            ->assertJsonPath('seeded', 4);
        $this->assertSame(4, Plan::count());
    }
}
