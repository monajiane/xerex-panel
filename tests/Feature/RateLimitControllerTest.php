<?php

namespace Tests\Feature;

use App\Models\RateLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_index_returns_policies(): void
    {
        RateLimit::factory()->count(3)->create();
        $this->getJson('/api/security/rate-limits')
            ->assertOk()
            ->assertJsonCount(3, 'policies');
    }

    public function test_index_filters_by_type(): void
    {
        RateLimit::factory()->perIp()->count(2)->create();
        RateLimit::factory()->perUser()->count(1)->create();
        $this->getJson('/api/security/rate-limits?limit_type=ip')
            ->assertOk()
            ->assertJsonCount(2, 'policies');
    }

    public function test_store_creates_policy(): void
    {
        $this->postJson('/api/security/rate-limits', [
            'name'           => 'My policy',
            'scope_type'     => 'global',
            'limit_type'     => 'ip',
            'max_requests'   => 100,
            'window_seconds' => 60,
            'action'         => 'block',
        ])
            ->assertCreated()
            ->assertJsonPath('policy.name', 'My policy');
    }

    public function test_store_validates_required_fields(): void
    {
        $this->postJson('/api/security/rate-limits', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'scope_type', 'limit_type', 'max_requests', 'window_seconds', 'action']);
    }

    public function test_show_returns_policy(): void
    {
        $policy = RateLimit::factory()->create();
        $this->getJson("/api/security/rate-limits/{$policy->id}")
            ->assertOk()
            ->assertJsonPath('policy.id', $policy->id);
    }

    public function test_update_modifies_policy(): void
    {
        $policy = RateLimit::factory()->perIp()->limits(10, 60)->create();
        $this->putJson("/api/security/rate-limits/{$policy->id}", [
            'max_requests' => 999,
        ])->assertOk()->assertJsonPath('policy.max_requests', 999);
    }

    public function test_destroy_removes_policy(): void
    {
        $policy = RateLimit::factory()->create();
        $this->deleteJson("/api/security/rate-limits/{$policy->id}")->assertNoContent();
        $this->assertDatabaseMissing('rate_limits', ['id' => $policy->id]);
    }

    public function test_toggle_flips_active_flag(): void
    {
        $policy = RateLimit::factory()->create(['is_active' => true]);
        $this->postJson("/api/security/rate-limits/{$policy->id}/toggle")
            ->assertOk()
            ->assertJsonPath('policy.is_active', false);
    }

    public function test_inspect_returns_current_count(): void
    {
        $policy = RateLimit::factory()->perIp()->limits(100, 60)->create();
        $this->getJson("/api/security/rate-limits/{$policy->id}/inspect?ip=1.2.3.4")
            ->assertOk()
            ->assertJsonStructure(['allowed', 'limit', 'current', 'retry_after']);
    }

    public function test_reset_clears_counter(): void
    {
        $policy = RateLimit::factory()->perIp()->limits(2, 60)->create();
        // Consume the quota
        $client = app(\App\Services\Security\RateLimiter::class);
        $client->check(new \App\Services\Security\RateLimitRequest(ip: '1.2.3.4'));
        $client->check(new \App\Services\Security\RateLimitRequest(ip: '1.2.3.4'));
        $client->check(new \App\Services\Security\RateLimitRequest(ip: '1.2.3.4'));
        $this->postJson("/api/security/rate-limits/{$policy->id}/reset", [], [
            'REMOTE_ADDR' => '1.2.3.4',
        ])->assertOk()->assertJsonPath('reset', true);
    }

    public function test_unauthenticated_cannot_access(): void
    {
        auth()->forgetGuards();
        $this->getJson('/api/security/rate-limits')->assertStatus(401);
    }
}
