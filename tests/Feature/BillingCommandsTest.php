<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_plans_command_creates_default_plans(): void
    {
        $this->artisan('xerex:billing:seed-plans')->assertSuccessful();
        $this->assertSame(4, Plan::count());
        $this->assertNotNull(Plan::where('is_default', true)->first());
    }

    public function test_seed_plans_is_idempotent(): void
    {
        $this->artisan('xerex:billing:seed-plans')->assertSuccessful();
        $this->artisan('xerex:billing:seed-plans')->assertSuccessful();
        $this->assertSame(4, Plan::count());
    }

    public function test_roll_subscriptions_expires_canceled_past_end(): void
    {
        $plan = Plan::factory()->create();
        $user = User::factory()->create();
        $sub = Subscription::factory()->canceled()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('xerex:billing:roll-subscriptions')->assertSuccessful();
        $this->assertSame(Subscription::STATUS_EXPIRED, $sub->fresh()->status);
    }

    public function test_roll_subscriptions_advances_active_past_end(): void
    {
        $plan = Plan::factory()->pro()->create();
        $user = User::factory()->create();
        $sub = Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'current_period_end' => now()->subDay(),
        ]);
        $oldEnd = $sub->current_period_end;

        $this->artisan('xerex:billing:roll-subscriptions')->assertSuccessful();
        $this->assertTrue($sub->fresh()->current_period_end->gt($oldEnd));
    }

    public function test_generate_invoices_creates_for_active_subs(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1000]);
        $user = User::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status'  => Subscription::STATUS_ACTIVE,
        ]);

        $this->artisan('xerex:billing:generate-invoices', [
            '--period' => now()->subMonth()->startOfMonth()->toDateString(),
        ])->assertSuccessful();
        $this->assertSame(1, \App\Models\Invoice::count());
    }

    public function test_generate_invoices_does_not_duplicate_for_same_period(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 1000]);
        $user = User::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
        ]);

        $period = now()->subMonth()->startOfMonth()->toDateString();
        $this->artisan('xerex:billing:generate-invoices', ['--period' => $period])->assertSuccessful();
        $this->artisan('xerex:billing:generate-invoices', ['--period' => $period])->assertSuccessful();
        $this->assertSame(1, \App\Models\Invoice::count());
    }
}
