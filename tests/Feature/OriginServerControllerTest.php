<?php

namespace Tests\Feature;

use App\Models\OriginServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OriginServerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_origins(): void
    {
        $user = User::factory()->create();
        OriginServer::factory()->count(3)->create(['user_id' => $user->id]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/origin-servers');
        $res->assertOk();
        $res->assertJsonCount('data', 3);
    }

    public function test_user_can_create_origin_with_failover_group(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/origin-servers', [
            'name'              => 'web-1',
            'host'              => '1.2.3.4',
            'port'              => 8080,
            'protocol'          => 'http',
            'failover_group'    => 'web-prod',
            'failover_priority' => 0,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('failover_group', 'web-prod');
        $res->assertJsonPath('failover_priority', 0);
    }

    public function test_user_can_join_existing_failover_group(): void
    {
        $user = User::factory()->create();
        $existing = OriginServer::factory()->inGroup('web-prod', 0)->create(['user_id' => $user->id]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/origin-servers', [
            'name'   => 'web-2',
            'host'   => '2.2.2.2',
            'port'   => 8080,
            'protocol' => 'http',
            'failover_group' => 'web-prod',
        ]);

        $res->assertCreated();
        $new = OriginServer::find($res->json('id'));
        $this->assertLessThan($existing->failover_priority, $new->failover_priority);
    }

    public function test_user_can_filter_by_failover_group(): void
    {
        $user = User::factory()->create();
        OriginServer::factory()->inGroup('web-prod', 0)->create(['user_id' => $user->id]);
        OriginServer::factory()->inGroup('web-prod', 1)->create(['user_id' => $user->id]);
        OriginServer::factory()->inGroup('api-prod', 0)->create(['user_id' => $user->id]);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/origin-servers?failover_group=web-prod');

        $res->assertOk();
        $res->assertJsonCount('data', 2);
    }
}
