<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WafRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WafRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_index_returns_rules(): void
    {
        WafRule::factory()->count(3)->create();
        $this->getJson('/api/security/waf/rules')
            ->assertOk()
            ->assertJsonStructure(['rules' => [['id', 'name', 'type', 'action']]])
            ->assertJsonCount(3, 'rules');
    }

    public function test_index_filters_by_type(): void
    {
        WafRule::factory()->xss()->count(2)->create();
        WafRule::factory()->sqlInjection()->count(1)->create();
        $this->getJson('/api/security/waf/rules?type=xss')
            ->assertOk()
            ->assertJsonCount(2, 'rules');
    }

    public function test_index_filters_by_active(): void
    {
        WafRule::factory()->count(2)->create();
        WafRule::factory()->count(1)->inactive()->create();
        $this->getJson('/api/security/waf/rules?is_active=1')
            ->assertOk()
            ->assertJsonCount(2, 'rules');
    }

    public function test_store_creates_rule(): void
    {
        $payload = [
            'name'    => 'Block foo',
            'type'    => WafRule::TYPE_REGEX,
            'pattern' => '(?i)foo',
            'target'  => WafRule::TARGET_URI,
            'action'  => WafRule::ACTION_BLOCK,
            'priority'=> 200,
        ];
        $this->postJson('/api/security/waf/rules', $payload)
            ->assertCreated()
            ->assertJsonPath('rule.name', 'Block foo');
        $this->assertDatabaseCount('waf_rules', 1);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->postJson('/api/security/waf/rules', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type', 'target', 'action']);
    }

    public function test_show_returns_rule(): void
    {
        $rule = WafRule::factory()->create();
        $this->getJson("/api/security/waf/rules/{$rule->id}")
            ->assertOk()
            ->assertJsonPath('rule.id', $rule->id);
    }

    public function test_update_modifies_rule(): void
    {
        $rule = WafRule::factory()->xss()->create(['priority' => 50]);
        $this->putJson("/api/security/waf/rules/{$rule->id}", [
            'priority' => 999,
        ])->assertOk()->assertJsonPath('rule.priority', 999);
    }

    public function test_destroy_removes_rule(): void
    {
        $rule = WafRule::factory()->create();
        $this->deleteJson("/api/security/waf/rules/{$rule->id}")->assertNoContent();
        $this->assertDatabaseMissing('waf_rules', ['id' => $rule->id]);
    }

    public function test_toggle_flips_active_flag(): void
    {
        $rule = WafRule::factory()->create(['is_active' => true]);
        $this->postJson("/api/security/waf/rules/{$rule->id}/toggle")
            ->assertOk()
            ->assertJsonPath('rule.is_active', false);
        $this->postJson("/api/security/waf/rules/{$rule->id}/toggle")
            ->assertOk()
            ->assertJsonPath('rule.is_active', true);
    }

    public function test_test_endpoint_evaluates_request(): void
    {
        WafRule::factory()->xss()->block()->create();
        $this->postJson('/api/security/waf/test', [
            'method' => 'GET',
            'uri'    => '/?x=<script>alert(1)</script>',
        ])
            ->assertOk()
            ->assertJsonStructure(['matches', 'request']);
    }

    public function test_test_endpoint_returns_no_matches_for_clean_request(): void
    {
        WafRule::factory()->xss()->block()->create();
        $this->postJson('/api/security/waf/test', [
            'method' => 'GET',
            'uri'    => '/page',
        ])
            ->assertOk()
            ->assertJsonPath('matches', []);
    }

    public function test_unauthenticated_cannot_access(): void
    {
        // No Sanctum::actingAs
        auth()->forgetGuards();
        $this->getJson('/api/security/waf/rules')->assertStatus(401);
    }
}
