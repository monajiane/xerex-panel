<?php

namespace Tests\Feature;

use App\Models\OriginServer;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group failover
 */
class FailoverGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_failover_group(): void
    {
        $user = User::factory()->admin()->create();
        $a = OriginServer::factory()->create(['failover_group' => null, 'failover_priority' => 0]);
        $b = OriginServer::factory()->create(['failover_group' => null, 'failover_priority' => 0]);

        $payload = [
            'group'   => 'web-prod',
            'origins' => [
                ['id' => $a->id, 'failover_priority' => 0],
                ['id' => $b->id, 'failover_priority' => 1],
            ],
        ];

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/failover-groups', $payload);

        $res->assertCreated();
        $res->assertJsonPath('group', 'web-prod');
        $res->assertJsonCount('members', 2);
    }

    public function test_user_can_list_groups(): void
    {
        $user = User::factory()->admin()->create();
        OriginServer::factory()->inGroup('web-prod', 0)->create();
        OriginServer::factory()->inGroup('web-prod', 1)->create();
        OriginServer::factory()->inGroup('api-prod', 0)->create();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/failover-groups');

        $res->assertOk();
        $res->assertJsonCount('data', 2);
    }

    public function test_user_can_show_group_with_members(): void
    {
        $user = User::factory()->admin()->create();
        $a = OriginServer::factory()->inGroup('web-prod', 0)->create();
        $b = OriginServer::factory()->inGroup('web-prod', 1)->create();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/failover-groups/web-prod');

        $res->assertOk();
        $res->assertJsonPath('group', 'web-prod');
        $res->assertJsonCount('members', 2);
    }

    public function test_user_can_reorder_group(): void
    {
        $user = User::factory()->admin()->create();
        $a = OriginServer::factory()->inGroup('web-prod', 0)->create();
        $b = OriginServer::factory()->inGroup('web-prod', 1)->create();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/failover-groups/web-prod/reorder", [
                'priorities' => [
                    ['id' => $a->id, 'failover_priority' => 1],
                    ['id' => $b->id, 'failover_priority' => 0],
                ],
            ]);

        $res->assertOk();
        $this->assertEquals(1, $a->fresh()->failover_priority);
        $this->assertEquals(0, $b->fresh()->failover_priority);
    }

    public function test_user_can_dissolve_group(): void
    {
        $user = User::factory()->admin()->create();
        OriginServer::factory()->inGroup('web-prod', 0)->create();
        OriginServer::factory()->inGroup('web-prod', 1)->create();

        $res = $this->actingAs($user, 'sanctum')->deleteJson('/api/failover-groups/web-prod');

        $res->assertOk();
        $this->assertEquals(0, OriginServer::where('failover_group', 'web-prod')->count());
    }
}
