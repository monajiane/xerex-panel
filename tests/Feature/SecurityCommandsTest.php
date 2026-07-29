<?php

namespace Tests\Feature;

use App\Models\IpList;
use App\Models\RateLimit;
use App\Models\WafRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_waf_creates_builtin_rules(): void
    {
        $this->artisan('xerex:security:seed-waf')->assertExitCode(0);
        $this->assertGreaterThan(0, WafRule::count());
        $this->assertNotNull(WafRule::where('name', 'Block XSS payloads')->first());
    }

    public function test_seed_waf_is_idempotent(): void
    {
        $this->artisan('xerex:security:seed-waf')->assertExitCode(0);
        $count = WafRule::count();
        $this->artisan('xerex:security:seed-waf')->assertExitCode(0);
        $this->assertSame($count, WafRule::count());
    }

    public function test_seed_waf_fresh_deactivates_existing(): void
    {
        $this->artisan('xerex:security:seed-waf')->assertExitCode(0);
        $this->artisan('xerex:security:seed-waf', ['--fresh' => true])->assertExitCode(0);
        // All rules should still be active (the presets re-activate them).
        $this->assertSame(0, WafRule::where('is_active', false)->count());
    }

    public function test_seed_rate_limits_creates_policies(): void
    {
        $this->artisan('xerex:security:seed-rate-limits')->assertExitCode(0);
        $this->assertGreaterThan(0, RateLimit::count());
        $this->assertNotNull(RateLimit::where('name', 'Global per-IP burst')->first());
    }

    public function test_prune_expiry_removes_expired(): void
    {
IpList::factory()->block()->expired()->count(3)->create();
        IpList::factory()->block()->count(2)->create();   // not expired
        $this->artisan('xerex:security:prune-expiry')->assertExitCode(0);
        $this->assertSame(2, IpList::count());
    }

    public function test_prune_expiry_dry_run_does_not_modify(): void
    {
        IpList::factory()->block()->expired()->count(3)->create();
        $this->artisan('xerex:security:prune-expiry', ['--dry-run' => true])
            ->assertExitCode(0);
        $this->assertSame(3, IpList::count());
    }
}
