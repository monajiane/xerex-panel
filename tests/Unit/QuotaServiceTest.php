<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Subscription;
use App\Models\Usage;
use App\Models\User;
use App\Services\BillingService;
use App\Services\QuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QuotaService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new QuotaService();
    }

    public function test_resolve_plan_returns_default_plan_when_no_subscription(): void
    {
        $free = Plan::factory()->free()->default()->create();
        $plan = $this->svc->resolvePlan(User::factory()->create());
        $this->assertNotNull($plan);
        $this->assertEquals($free->id, $plan->id);
    }

    public function test_resolve_plan_returns_subscription_plan_when_active(): void
    {
        $free = Plan::factory()->free()->create();
        $pro  = Plan::factory()->pro()->create();
        $user = User::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $pro->id,
            'status'  => Subscription::STATUS_ACTIVE,
        ]);
        $plan = $this->svc->resolvePlan($user);
        $this->assertEquals($pro->id, $plan->id);
    }

    public function test_resolve_plan_falls_back_to_default_when_subscription_expired(): void
    {
        $default = Plan::factory()->free()->default()->create();
        $pro = Plan::factory()->pro()->create();
        $user = User::factory()->create();
        Subscription::factory()->expired()->create([
            'user_id' => $user->id,
            'plan_id' => $pro->id,
        ]);
        $this->assertEquals($default->id, $this->svc->resolvePlan($user)->id);
    }

    public function test_admin_bypasses_all_quotas(): void
    {
        $plan = Plan::factory()->free()->create();
        $plan->limits()->create([
            'metric' => 'domains', 'value' => 0, 'period' => 'lifetime', 'is_soft' => false,
        ]);
        $admin = User::factory()->admin()->create();
        $result = $this->svc->check($admin, 'domains', delta: 1000);
        $this->assertTrue($result->allowed);
        $this->assertTrue($result->unlimited);
    }

    public function test_check_returns_unlimited_when_plan_has_no_limit(): void
    {
        $plan = Plan::factory()->free()->create();
        $user = User::factory()->create();
        $result = $this->svc->check($user, 'domains', delta: 1);
        $this->assertTrue($result->allowed);
        $this->assertTrue($result->unlimited);
    }

    public function test_check_returns_unlimited_when_limit_is_negative_one(): void
    {
        $plan = Plan::factory()->create();
        $plan->limits()->create(['metric' => 'edges', 'value' => -1, 'period' => 'lifetime']);
        $user = User::factory()->create();
        $result = $this->svc->check($user, 'edges', delta: 9999);
        $this->assertTrue($result->unlimited);
    }

    public function test_check_allows_when_under_limit(): void
    {
        $plan = Plan::factory()->create();
        $plan->limits()->create(['metric' => 'domains', 'value' => 5, 'period' => 'lifetime']);
        $user = User::factory()->create();

        $result = $this->svc->check($user, 'domains', delta: 3);
        $this->assertTrue($result->allowed);
        $this->assertSame(0,  $result->used);
        $this->assertSame(5,  $result->limit);
        $this->assertSame(5,  $result->remaining);
    }

    public function test_check_denies_when_at_or_over_limit(): void
    {
        $plan = Plan::factory()->create();
        $plan->limits()->create(['metric' => 'domains', 'value' => 2, 'period' => 'lifetime']);
        $user = User::factory()->create();

        // Already at the limit -> denied
        Usage::create([
            'user_id' => $user->id, 'metric' => 'domains', 'quantity' => 2,
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        ]);
        $result = $this->svc->check($user, 'domains', delta: 1);
        $this->assertFalse($result->allowed);
        $this->assertSame(0,  $result->remaining);
    }

    public function test_check_marks_soft_when_over_threshold(): void
    {
        $plan = Plan::factory()->create();
        $plan->limits()->create(['metric' => 'domains', 'value' => 10, 'period' => 'lifetime', 'is_soft' => true]);
        $user = User::factory()->create();

        Usage::create([
            'user_id' => $user->id, 'metric' => 'domains', 'quantity' => 9,
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        ]);
        $result = $this->svc->check($user, 'domains', delta: 1);
        $this->assertTrue($result->allowed);
        $this->assertTrue($result->isSoft());
    }

    public function test_snapshot_returns_one_row_per_metric_on_plan(): void
    {
        $plan = Plan::factory()->create();
        $plan->limits()->create(['metric' => 'domains', 'value' => 3, 'period' => 'lifetime']);
        $plan->limits()->create(['metric' => 'edges',   'value' => -1, 'period' => 'lifetime']);
        $user = User::factory()->create();
        Usage::create([
            'user_id' => $user->id, 'metric' => 'domains', 'quantity' => 1,
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        ]);

        $snap = $this->svc->snapshot($user);
        $this->assertCount(2, $snap);

$domains = $snap->firstWhere('metric', 'domains');
        $this->assertSame(1, $domains['used']);
        $this->assertSame(3, $domains['limit']);
        $this->assertSame(2, $domains['remaining']);
        $this->assertFalse($domains['unlimited']);

        $edges = $snap->firstWhere('metric', 'edges');
        $this->assertTrue($edges['unlimited']);
    }

    public function test_window_for_returns_correct_period_boundaries(): void
    {
        $hour = $this->svc->windowFor('hour', \Carbon\CarbonImmutable::parse('2026-04-15 10:23:45'));
        $this->assertEquals('2026-04-15 10:00:00', $hour[0]->toDateTimeString());
        $this->assertEquals('2026-04-15 11:00:00', $hour[1]->toDateTimeString());

        $month = $this->svc->windowFor('month', \Carbon\CarbonImmutable::parse('2026-04-15 10:23:45'));
        $this->assertEquals('2026-04-01 00:00:00', $month[0]->toDateTimeString());
        $this->assertEquals('2026-05-01 00:00:00', $month[1]->toDateTimeString());
    }
}
