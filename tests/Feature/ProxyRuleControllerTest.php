<?php

namespace Tests\Feature;

use App\Models\EdgeServer;
use App\Models\OriginServer;
use App\Models\ProxyRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_own_rules(): void
    {
        $user = User::factory()->create();
        $edge = EdgeServer::factory()->create(['user_id' => $user->id]);
        $origin = OriginServer::factory()->create(['user_id' => $user->id]);
        ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'origin_server_id' => $origin->id,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/proxy-rules');
        $res->assertOk();
        $res->assertJsonCount('data', 1);
    }

    public function test_user_can_create_rule(): void
    {
        $user = User::factory()->create();
        $edge = EdgeServer::factory()->create(['user_id' => $user->id]);
        $origin = OriginServer::factory()->create(['user_id' => $user->id]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/proxy-rules', [
            'edge_server_id'   => $edge->id,
            'origin_server_id' => $origin->id,
            'type'             => ProxyRule::TYPE_HTTP,
            'path'             => '/',
            'listen_port'      => 443,
        ]);

        $res->assertCreated();
        $this->assertDatabaseCount('proxy_rules', 1);
    }

    public function test_user_can_toggle_rule(): void
    {
        $user = User::factory()->create();
        $edge = EdgeServer::factory()->create(['user_id' => $user->id]);
        $origin = OriginServer::factory()->create(['user_id' => $user->id]);
        $rule = ProxyRule::factory()->create([
            'edge_server_id'   => $edge->id,
            'origin_server_id' => $origin->id,
            'enabled'          => true,
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/proxy-rules/{$rule->id}/toggle");

        $res->assertOk();
        $this->assertFalse($rule->fresh()->enabled);
    }

    public function test_user_can_delete_rule(): void
    {
        $user = User::factory()->create();
        $edge = EdgeServer::factory()->create(['user_id' => $user->id]);
        $origin = OriginServer::factory()->create(['user_id' => $user->id]);
        $rule = ProxyRule::factory()->create([
            'edge_server_id'   => $edge->id,
            'origin_server_id' => $origin->id,
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/proxy-rules/{$rule->id}");

        $res->assertOk();
        $this->assertSoftDeleted('proxy_rules', ['id' => $rule->id]);
    }
}
