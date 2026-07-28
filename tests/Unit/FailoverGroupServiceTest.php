<?php

namespace Tests\Unit;

use App\Models\OriginServer;
use App\Services\FailoverGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailoverGroupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotes_healthy_sibling_when_leader_fails(): void
    {
        $leader = OriginServer::factory()
            ->inGroup('web-prod', 0)
            ->healthy()
            ->create();
        $backup = OriginServer::factory()
            ->inGroup('web-prod', 1)
            ->healthy()
            ->create();

        $svc = new FailoverGroupService();
        $promoted = $svc->promoteReplacement($leader);

        $this->assertNotNull($promoted);
        $this->assertEquals($backup->id, $promoted->id);
        $this->assertLessThan($leader->fresh()->failover_priority, $promoted->failover_priority);
    }

    public function test_promotion_returns_null_when_no_healthy_sibling(): void
    {
        $leader = OriginServer::factory()
            ->inGroup('web-prod', 0)
            ->healthy()
            ->create();
        $backup = OriginServer::factory()
            ->inGroup('web-prod', 1)
            ->down()
            ->create();

        $svc = new FailoverGroupService();
        $promoted = $svc->promoteReplacement($leader);

        $this->assertNull($promoted);
    }

    public function test_promotion_returns_null_when_no_group(): void
    {
        $solo = OriginServer::factory()->healthy()->create();

        $svc = new FailoverGroupService();
        $promoted = $svc->promoteReplacement($solo);

        $this->assertNull($promoted);
    }

    public function test_pick_next_healthy_orders_by_priority(): void
    {
        $a = OriginServer::factory()->inGroup('web-prod', 0)->down()->create();
        $b = OriginServer::factory()->inGroup('web-prod', 1)->healthy()->create();
        $c = OriginServer::factory()->inGroup('web-prod', 2)->healthy()->create();

        $svc = new FailoverGroupService();
        $next = $svc->pickNextHealthy('web-prod', exclude: $a->id);

        $this->assertNotNull($next);
        $this->assertEquals($b->id, $next->id);
    }

    public function test_summary_returns_one_entry_per_group(): void
    {
        OriginServer::factory()->inGroup('web-prod', 0)->healthy()->create();
        OriginServer::factory()->inGroup('web-prod', 1)->healthy()->create();
        OriginServer::factory()->inGroup('api-prod', 0)->healthy()->create();
        OriginServer::factory()->create(); // ungrouped

        $svc = new FailoverGroupService();
        $summary = $svc->summary();

        $this->assertCount(2, $summary);
        $this->assertEquals('web-prod', $summary[0]['group']);
        $this->assertEquals(2, $summary[0]['members']);
    }

    public function test_reorder_updates_priorities(): void
    {
        $a = OriginServer::factory()->inGroup('web-prod', 0)->create();
        $b = OriginServer::factory()->inGroup('web-prod', 1)->create();
        $c = OriginServer::factory()->inGroup('web-prod', 2)->create();

        $svc = new FailoverGroupService();
        $count = $svc->reorderGroup('web-prod', [
            $a->id => 5,
            $b->id => 2,
            $c->id => 0,
        ]);

        $this->assertEquals(3, $count);
        $this->assertEquals(5, $a->fresh()->failover_priority);
        $this->assertEquals(2, $b->fresh()->failover_priority);
        $this->assertEquals(0, $c->fresh()->failover_priority);
    }
}
