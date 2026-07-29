<?php

namespace Tests\Unit;

use App\Models\RateLimit;
use App\Services\Security\RateLimitRequest;
use App\Services\Security\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected RateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->limiter = app(RateLimiter::class);
    }

    public function test_unlimited_when_no_policies(): void
    {
        $result = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $this->assertTrue($result->allowed);
    }

    public function test_first_request_within_limit_is_allowed(): void
    {
        RateLimit::factory()->perIp()->limits(10, 60)->create();
        $result = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $this->assertTrue($result->allowed);
        $this->assertSame(1, $result->current);
    }

    public function test_request_at_limit_still_allowed(): void
    {
        RateLimit::factory()->perIp()->limits(3, 60)->create();
        for ($i = 0; $i < 3; $i++) {
            $r = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
            $this->assertTrue($r->allowed);
        }
    }

    public function test_request_over_limit_is_blocked(): void
    {
        RateLimit::factory()->perIp()->limits(3, 60)->create();
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        }
        $result = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $this->assertFalse($result->allowed);
        $this->assertSame('block', $result->action);
    }

    public function test_different_ips_have_independent_counters(): void
    {
        RateLimit::factory()->perIp()->limits(2, 60)->create();
        $this->limiter->check(new RateLimitRequest(ip: '1.1.1.1'));
        $this->limiter->check(new RateLimitRequest(ip: '1.1.1.1'));
        $this->limiter->check(new RateLimitRequest(ip: '2.2.2.2'));
        $this->limiter->check(new RateLimitRequest(ip: '2.2.2.2'));
        // 1.1.1.1 should now be blocked
        $r = $this->limiter->check(new RateLimitRequest(ip: '1.1.1.1'));
        $this->assertFalse($r->allowed);
        // 2.2.2.2 has 4 hits (3 + 4th) — depends on counter
        $r2 = $this->limiter->check(new RateLimitRequest(ip: '2.2.2.2'));
        $this->assertFalse($r2->allowed);
    }

    public function test_per_user_bucketing(): void
    {
        RateLimit::factory()->perUser()->limits(2, 60)->create();
        $a = new RateLimitRequest(ip: '1.1.1.1', userId: 1);
        $b = new RateLimitRequest(ip: '1.1.1.1', userId: 2);
        $this->limiter->check($a);
        $this->limiter->check($a);
        $this->limiter->check($a); // 3rd, blocked
        $r = $this->limiter->check($a);
        $this->assertFalse($r->allowed);
        // user 2 still has quota
        $this->limiter->check($b);
        $r2 = $this->limiter->check($b);
        $this->assertTrue($r2->allowed);
    }

    public function test_per_path_bucketing(): void
    {
        RateLimit::factory()->perPath()->limits(2, 60)->create();
        $login    = new RateLimitRequest(ip: '1.1.1.1', path: '/auth/login');
        $register = new RateLimitRequest(ip: '1.1.1.1', path: '/auth/register');
        $this->limiter->check($login);
        $this->limiter->check($login);
        $this->limiter->check($login); // over
        $r = $this->limiter->check($login);
        $this->assertFalse($r->allowed);
        $r2 = $this->limiter->check($register);
        $this->assertTrue($r2->allowed);
    }

    public function test_burst_multiplier_raises_effective_max(): void
    {
        $policy = RateLimit::factory()->perIp()->limits(10, 60)->create([
            'burst_multiplier' => 1.5,
        ]);
        $this->assertSame(15, $policy->effectiveMax());
    }

    public function test_inspect_does_not_increment(): void
    {
        RateLimit::factory()->perIp()->limits(5, 60)->create();
        $r1 = $this->limiter->inspect(new RateLimitRequest(ip: '1.2.3.4'));
        $r2 = $this->limiter->inspect(new RateLimitRequest(ip: '1.2.3.4'));
        $this->assertSame(0, $r1->current);
        $this->assertSame(0, $r2->current);
    }

    public function test_inactive_policies_are_ignored(): void
    {
        RateLimit::factory()->perIp()->limits(1, 60)->inactive()->create();
        $r1 = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $r2 = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $r3 = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $this->assertTrue($r1->allowed);
        $this->assertTrue($r2->allowed);
        $this->assertTrue($r3->allowed);
    }

    public function test_challenge_action_is_propagated(): void
    {
        RateLimit::factory()->perIp()->limits(1, 60)->challenge()->create();
        $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $r = $this->limiter->check(new RateLimitRequest(ip: '1.2.3.4'));
        $this->assertFalse($r->allowed);
        $this->assertSame('challenge', $r->action);
    }
}
