<?php

namespace Tests\Unit;

use App\Models\TrafficLog;
use App\Models\TrafficRollup;
use App\Services\TrafficAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrafficAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected TrafficAggregator $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TrafficAggregator();
    }

    public function test_rebuild_for_hour_aggregates_requests_and_bytes(): void
    {
        $hour = CarbonImmutable::parse('2026-03-15 10:00:00');
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id'   => $edge->id,
            'domain_id'        => $dom->id,
        ]);

        // 4 hits inside the hour, 1 outside
        TrafficLog::factory()->count(3)->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(5))
            ->withBytes(100, 1000)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(20))
            ->withBytes(200, 2000)->withStatus(200)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addHour()->addMinutes(5))
            ->create();

        $rows = $this->svc->rebuildForHour($hour);

        $this->assertSame(1, $rows, 'one (edge,domain,rule) bucket was rolled up');
        $rollup = TrafficRollup::first();
        $this->assertSame(4,  $rollup->requests);
        $this->assertSame(500, $rollup->bytes_in);
        $this->assertSame(5000, $rollup->bytes_out);
        $this->assertSame($edge->id,  $rollup->edge_server_id);
        $this->assertSame($dom->id,   $rollup->domain_id);
        $this->assertSame($rule->id,  $rollup->proxy_rule_id);
        $this->assertTrue($hour->equalTo($rollup->bucket));
    }

    public function test_rebuild_for_hour_breaks_status_codes_correctly(): void
    {
        $hour = CarbonImmutable::parse('2026-03-15 11:00:00');
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(1))->withStatus(200)->count(3)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(2))->withStatus(301)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(3))->withStatus(404)->count(2)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(4))->withStatus(503)->create();

        $this->svc->rebuildForHour($hour);
        $r = TrafficRollup::first();

        $this->assertSame(7, $r->requests);
        $this->assertSame(3, $r->status_2xx);
        $this->assertSame(1, $r->status_3xx);
        $this->assertSame(2, $r->status_4xx);
        $this->assertSame(1, $r->status_5xx);
    }

    public function test_rebuild_for_hour_counts_unique_clients(): void
    {
        $hour = CarbonImmutable::parse('2026-03-15 12:00:00');
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(1))->fromClient('10.0.0.1')->count(2)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(2))->fromClient('10.0.0.2')->count(3)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(3))->fromClient('10.0.0.1')->create();

        $this->svc->rebuildForHour($hour);
        $r = TrafficRollup::first();
        $this->assertSame(2, $r->unique_clients, 'COUNT(DISTINCT client_ip)');
    }

    public function test_rebuild_for_hour_sums_cache_hits_and_misses(): void
    {
        $hour = CarbonImmutable::parse('2026-03-15 13:00:00');
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(1))->cached()->count(7)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(2))->uncached()->count(3)->create();

        $this->svc->rebuildForHour($hour);
        $r = TrafficRollup::first();
        $this->assertSame(7, $r->cache_hits);
        $this->assertSame(3, $r->cache_misses);
    }

    public function test_rebuild_for_hour_is_idempotent(): void
    {
        $hour = CarbonImmutable::parse('2026-03-15 14:00:00');
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($hour->addMinutes(5))->count(5)->create();

        $this->svc->rebuildForHour($hour);
        $this->svc->rebuildForHour($hour);
        $this->svc->rebuildForHour($hour);

        $this->assertSame(1, TrafficRollup::count(), 'still one rollup row');
        $r = TrafficRollup::first();
        $this->assertSame(5, $r->requests, 'counts did not double');
    }

    public function test_rebuild_range_walks_every_hour(): void
    {
        $start = CarbonImmutable::parse('2026-03-15 10:00:00');
        $end   = CarbonImmutable::parse('2026-03-15 13:00:00'); // 4 hours: 10, 11, 12, 13

        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        // one log per hour for 4 hours
        for ($h = 0; $h < 4; $h++) {
            TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
                ->forRule($rule->id)->at($start->addHours($h)->addMinutes(5))
                ->create();
        }

        $total = $this->svc->rebuildRange($start, $end);
        $this->assertSame(4, $total);
        $this->assertSame(4, TrafficRollup::count());
    }

    public function test_top_domains_orders_by_request_count(): void
    {
        $edge = \App\Models\EdgeServer::factory()->create();
        $busy = \App\Models\Domain::factory()->create(['domain' => 'busy.example.com']);
        $mid  = \App\Models\Domain::factory()->create(['domain' => 'mid.example.com']);
        $slow = \App\Models\Domain::factory()->create(['domain' => 'slow.example.com']);
        $ruleBusy = \App\Models\ProxyRule::factory()->create(['edge_server_id' => $edge->id, 'domain_id' => $busy->id]);
        $ruleMid  = \App\Models\ProxyRule::factory()->create(['edge_server_id' => $edge->id, 'domain_id' => $mid->id]);
        $ruleSlow = \App\Models\ProxyRule::factory()->create(['edge_server_id' => $edge->id, 'domain_id' => $slow->id]);

        $bucket = CarbonImmutable::parse('2026-03-15 10:00:00');
        TrafficLog::factory()->forEdge($edge->id)->forDomain($busy->id)->forRule($ruleBusy->id)
            ->at($bucket)->count(20)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($mid->id)->forRule($ruleMid->id)
            ->at($bucket)->count(5)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($slow->id)->forRule($ruleSlow->id)
            ->at($bucket)->count(1)->create();

        $this->svc->rebuildForHour($bucket);
        $top = $this->svc->topDomains(10);
        $domains = $top->pluck('domain')->all();
        $this->assertSame(['busy.example.com', 'mid.example.com', 'slow.example.com'], $domains);
        $first = $top->first();
        $this->assertEquals(20, (int) $first->requests);
    }

    public function test_top_proxy_rules_includes_domain_and_uuid(): void
    {
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        $bucket = CarbonImmutable::parse('2026-03-15 10:00:00');
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)
            ->forRule($rule->id)->at($bucket)->count(15)->create();
        $this->svc->rebuildForHour($bucket);

        $top = $this->svc->topProxyRules(10);
        $row = $top->first();
        $this->assertSame($rule->uuid, $row->uuid);
        $this->assertSame($dom->domain, $row->domain);
        $this->assertEquals(15, (int) $row->requests);
    }

    public function test_series_groups_by_hour(): void
    {
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        $h1 = CarbonImmutable::parse('2026-03-15 10:00:00');
        $h2 = CarbonImmutable::parse('2026-03-15 11:00:00');
        $h3 = CarbonImmutable::parse('2026-03-15 12:00:00');

        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h1->addMinutes(5))->count(3)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h2->addMinutes(5))->count(7)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h3->addMinutes(5))->count(11)->create();

        $this->svc->rebuildForHour($h1);
        $this->svc->rebuildForHour($h2);
        $this->svc->rebuildForHour($h3);

        $series = $this->svc->series('hour', $h1, $h3);
        $this->assertCount(3, $series);
        $this->assertEquals(3,  (int) $series[0]->requests);
        $this->assertEquals(7,  (int) $series[1]->requests);
        $this->assertEquals(11, (int) $series[2]->requests);
    }

    public function test_series_filters_by_edge_id(): void
    {
        $edge1 = \App\Models\EdgeServer::factory()->create();
        $edge2 = \App\Models\EdgeServer::factory()->create();
        $dom   = \App\Models\Domain::factory()->create();
        $rule1 = \App\Models\ProxyRule::factory()->create(['edge_server_id' => $edge1->id, 'domain_id' => $dom->id]);
        $rule2 = \App\Models\ProxyRule::factory()->create(['edge_server_id' => $edge2->id, 'domain_id' => $dom->id]);

        $h = CarbonImmutable::parse('2026-03-15 10:00:00');
        TrafficLog::factory()->forEdge($edge1->id)->forDomain($dom->id)->forRule($rule1->id)
            ->at($h)->count(4)->create();
        TrafficLog::factory()->forEdge($edge2->id)->forDomain($dom->id)->forRule($rule2->id)
            ->at($h)->count(9)->create();
        $this->svc->rebuildForHour($h);

        $series = $this->svc->series('hour', $h, $h, edgeId: $edge1->id);
        $this->assertCount(1, $series);
        $this->assertEquals(4, (int) $series->first()->requests);
    }

    public function test_status_breakdown_returns_correct_counts(): void
    {
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create(['edge_server_id' => $edge->id, 'domain_id' => $dom->id]);

        $h = CarbonImmutable::parse('2026-03-15 10:00:00');
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h)->withStatus(200)->count(10)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h)->withStatus(404)->count(2)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h)->withStatus(500)->count(1)->create();
        $this->svc->rebuildForHour($h);

        $bd = $this->svc->statusBreakdown($h, $h);
        $this->assertSame(10, $bd['2xx']);
        $this->assertSame(0,  $bd['3xx']);
        $this->assertSame(2,  $bd['4xx']);
        $this->assertSame(1,  $bd['5xx']);
    }

    public function test_cache_hit_ratio_calculates_percentage(): void
    {
        $edge = \App\Models\EdgeServer::factory()->create();
        $dom  = \App\Models\Domain::factory()->create();
        $rule = \App\Models\ProxyRule::factory()->create(['edge_server_id' => $edge->id, 'domain_id' => $dom->id]);

        $h = CarbonImmutable::parse('2026-03-15 10:00:00');
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h)->cached()->count(75)->create();
        TrafficLog::factory()->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h)->uncached()->count(25)->create();
        $this->svc->rebuildForHour($h);

        $ratio = $this->svc->cacheHitRatio($h, $h);
        $this->assertEquals(75.0, $ratio);
    }

    public function test_cache_hit_ratio_is_zero_when_no_traffic(): void
    {
        $ratio = $this->svc->cacheHitRatio(
            CarbonImmutable::parse('2026-03-15 10:00:00'),
            CarbonImmutable::parse('2026-03-15 11:00:00'),
        );
        $this->assertSame(0.0, $ratio);
    }
}
