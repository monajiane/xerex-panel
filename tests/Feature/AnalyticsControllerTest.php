<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\EdgeServer;
use App\Models\ProxyRule;
use App\Models\TrafficLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected EdgeServer $edge;
    protected Domain $domain;
    protected ProxyRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user   = User::factory()->create();
        $this->edge   = EdgeServer::factory()->create();
        $this->domain = Domain::factory()->create();
        $this->rule   = ProxyRule::factory()->create([
            'edge_server_id' => $this->edge->id,
            'domain_id'      => $this->domain->id,
        ]);
    }

    /** Convenience: seed N logs in this hour for the configured edge/domain/rule. */
    protected function seedHour(CarbonImmutable $hour, int $count, int $status = 200, bool $cached = false): void
    {
        TrafficLog::factory()
            ->forEdge($this->edge->id)
            ->forDomain($this->domain->id)
            ->forRule($this->rule->id)
            ->at($hour->addMinutes(15))
            ->withStatus($status)
            ->count($count)
            ->state(['cached' => $cached])
            ->create();
    }

    /* -----------------------------------------------------------------
     | Auth
     * ----------------------------------------------------------------- */

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/analytics/series')->assertStatus(401);
        $this->getJson('/api/analytics/summary')->assertStatus(401);
        $this->getJson('/api/analytics/top-domains')->assertStatus(401);
        $this->getJson('/api/analytics/top-rules')->assertStatus(401);
    }

    /* -----------------------------------------------------------------
     | series
     * ----------------------------------------------------------------- */

    public function test_series_returns_empty_array_when_no_rollups(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/series');

        $res->assertOk()
            ->assertJsonStructure(['interval', 'from', 'to', 'points'])
            ->assertJsonPath('interval', 'hour')
            ->assertJsonPath('points', []);
    }

    public function test_series_returns_rebuilt_buckets(): void
    {
        $h1 = CarbonImmutable::parse('2026-04-01 10:00:00');
        $h2 = CarbonImmutable::parse('2026-04-01 11:00:00');

        $this->seedHour($h1, 5);
        $this->seedHour($h2, 9);

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $h1->toDateTimeString(),
            '--to'   => $h2->toDateTimeString(),
        ])->assertSuccessful();

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/series?from=' . urlencode($h1->toIso8601String())
                . '&to=' . urlencode($h2->addMinutes(59)->toIso8601String()));

        $res->assertOk();
        $points = $res->json('points');
        $this->assertCount(2, $points);
        $this->assertEquals(5, (int) $points[0]['requests']);
        $this->assertEquals(9, (int) $points[1]['requests']);
    }

    public function test_series_supports_day_interval(): void
    {
        $day1 = CarbonImmutable::parse('2026-04-01 10:00:00');
        $day2 = CarbonImmutable::parse('2026-04-02 10:00:00');

        $this->seedHour($day1, 4);
        $this->seedHour($day2, 8);

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $day1->toDateTimeString(),
            '--to'   => $day2->toDateTimeString(),
        ])->assertSuccessful();

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/series?interval=day'
                . '&from=' . urlencode($day1->toIso8601String())
                . '&to='   . urlencode($day2->toIso8601String()));

        $res->assertOk()
            ->assertJsonPath('interval', 'day');
        $points = $res->json('points');
        $this->assertCount(2, $points);
        $this->assertEquals(4, (int) $points[0]['requests']);
        $this->assertEquals(8, (int) $points[1]['requests']);
    }

    public function test_series_rejects_invalid_interval_and_falls_back_to_hour(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/series?interval=garbage');
        $res->assertOk()->assertJsonPath('interval', 'hour');
    }

    public function test_series_filters_by_edge_and_domain_id(): void
    {
        $h = CarbonImmutable::parse('2026-04-01 10:00:00');
        $this->seedHour($h, 4);

        // a different edge / domain / rule, separate from the one in setUp()
        $otherEdge   = EdgeServer::factory()->create();
        $otherDomain = Domain::factory()->create();
        $otherRule   = ProxyRule::factory()->create([
            'edge_server_id' => $otherEdge->id,
            'domain_id'      => $otherDomain->id,
        ]);
        TrafficLog::factory()
            ->forEdge($otherEdge->id)->forDomain($otherDomain->id)->forRule($otherRule->id)
            ->at($h)->count(7)->create();

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $h->toDateTimeString(),
            '--to'   => $h->toDateTimeString(),
        ])->assertSuccessful();

        // unfiltered: total = 11
        $all = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/series?from=' . urlencode($h->toIso8601String())
                . '&to='   . urlencode($h->addHour()->toIso8601String()));
        $this->assertEquals(11, (int) $all->json('points.0.requests'));

        // filtered by this->edge: 4
        $byEdge = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/series?edge_id=' . $this->edge->id
                . '&from=' . urlencode($h->toIso8601String())
                . '&to='   . urlencode($h->addHour()->toIso8601String()));
        $this->assertEquals(4, (int) $byEdge->json('points.0.requests'));

        // filtered by this->domain: 4
        $byDomain = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/series?domain_id=' . $this->domain->id
                . '&from=' . urlencode($h->toIso8601String())
                . '&to='   . urlencode($h->addHour()->toIso8601String()));
        $this->assertEquals(4, (int) $byDomain->json('points.0.requests'));
    }

    /* -----------------------------------------------------------------
     | summary
     * ----------------------------------------------------------------- */

    public function test_summary_returns_zeros_when_empty(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/analytics/summary');
        $res->assertOk()
            ->assertJsonPath('total.requests', 0)
            ->assertJsonPath('total.bytes', 0)
            ->assertJsonPath('status.2xx', 0)
            ->assertJsonPath('status.3xx', 0)
            ->assertJsonPath('status.4xx', 0)
            ->assertJsonPath('status.5xx', 0)
            ->assertJsonPath('status.total', 0)
            ->assertJsonPath('cache_hit_ratio_pct', 0);
    }

    public function test_summary_returns_aggregated_totals(): void
    {
        $h = CarbonImmutable::parse('2026-04-01 10:00:00');
        // 6x 200, 1x 404, 1x 500  +  half cached
        $this->seedHour($h, 4, 200, true);
        $this->seedHour($h, 2, 200, false);
        $this->seedHour($h, 1, 404, false);
        $this->seedHour($h, 1, 500, false);

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $h->toDateTimeString(),
            '--to'   => $h->toDateTimeString(),
        ])->assertSuccessful();

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/summary?from=' . urlencode($h->toIso8601String())
                . '&to='   . urlencode($h->addHour()->toIso8601String()));

        $res->assertOk()
            ->assertJsonPath('total.requests', 8)
            ->assertJsonPath('status.2xx', 6)
            ->assertJsonPath('status.4xx', 1)
            ->assertJsonPath('status.5xx', 1)
            ->assertJsonPath('status.total', 8)
            ->assertJsonPath('cache_hit_ratio_pct', 50.0);
    }

    public function test_summary_filters_by_domain_id(): void
    {
        $h = CarbonImmutable::parse('2026-04-01 10:00:00');
        $this->seedHour($h, 3, 200, false);

        // separate domain/edge
        $otherEdge   = EdgeServer::factory()->create();
        $otherDomain = Domain::factory()->create();
        $otherRule   = ProxyRule::factory()->create([
            'edge_server_id' => $otherEdge->id,
            'domain_id'      => $otherDomain->id,
        ]);
        TrafficLog::factory()
            ->forEdge($otherEdge->id)->forDomain($otherDomain->id)->forRule($otherRule->id)
            ->at($h)->count(9)->withStatus(200)->create();

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $h->toDateTimeString(),
            '--to'   => $h->toDateTimeString(),
        ])->assertSuccessful();

        $filtered = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/summary?domain_id=' . $this->domain->id
. '&from=' . urlencode($h->toIso8601String())
                . '&to='   . urlencode($h->addHour()->toIso8601String()));

        $filtered->assertOk()->assertJsonPath('total.requests', 3);
    }

    /* -----------------------------------------------------------------
     | top-domains / top-rules
     * ----------------------------------------------------------------- */

    public function test_top_domains_returns_aggregated_rows(): void
    {
        $h = CarbonImmutable::parse('2026-04-01 10:00:00');
        $this->seedHour($h, 5);

        $other = Domain::factory()->create(['domain' => 'other.example.com']);
        $otherEdge = EdgeServer::factory()->create();
        $otherRule = ProxyRule::factory()->create([
            'edge_server_id' => $otherEdge->id,
            'domain_id'      => $other->id,
        ]);
        TrafficLog::factory()
            ->forEdge($otherEdge->id)->forDomain($other->id)->forRule($otherRule->id)
            ->at($h)->count(11)->create();

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $h->toDateTimeString(),
            '--to'   => $h->toDateTimeString(),
        ])->assertSuccessful();

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/top-domains?limit=5');
        $res->assertOk()
            ->assertJsonPath('limit', 5)
            ->assertJsonCount('rows', 2);

        $rows = collect($res->json('rows'));
        $first = $rows->firstWhere('domain', 'other.example.com');
        $this->assertEquals(11, (int) $first['requests']);
    }

    public function test_top_domains_limit_is_clamped_between_1_and_50(): void
    {
        // 0 -> clamped up to 1
        $r1 = $this->actingAs($this->user, 'sanctum')->getJson('/api/analytics/top-domains?limit=0');
        $r1->assertOk()->assertJsonPath('limit', 1);

        // 999 -> clamped down to 50
        $r2 = $this->actingAs($this->user, 'sanctum')->getJson('/api/analytics/top-domains?limit=999');
        $r2->assertOk()->assertJsonPath('limit', 50);
    }

    public function test_top_rules_returns_aggregated_rows(): void
    {
        $h = CarbonImmutable::parse('2026-04-01 10:00:00');
        $this->seedHour($h, 7);

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $h->toDateTimeString(),
            '--to'   => $h->toDateTimeString(),
        ])->assertSuccessful();

        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/analytics/top-rules');
        $res->assertOk()
            ->assertJsonCount('rows', 1);

        $row = $res->json('rows.0');
        $this->assertEquals($this->rule->uuid, $row['uuid']);
        $this->assertEquals($this->domain->domain, $row['domain']);
        $this->assertEquals(7, (int) $row['requests']);
    }

    /* -----------------------------------------------------------------
     | rebuild
     * ----------------------------------------------------------------- */

    public function test_rebuild_is_forbidden_for_non_admin(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/analytics/rebuild', []);
        $res->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_rebuild_runs_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $h = CarbonImmutable::parse('2026-04-01 10:00:00');
        $this->seedHour($h, 3);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/analytics/rebuild', [
            'from' => $h->toIso8601String(),
            'to'   => $h->toIso8601String(),
        ]);

        $res->assertOk()->assertJsonStructure(['rebuilt_rows']);
        $this->assertGreaterThanOrEqual(1, $res->json('rebuilt_rows'));
        $this->assertDatabaseCount('traffic_rollups', 1);
    }

    public function test_rebuild_uses_default_window_when_no_body(): void
    {
        $admin = User::factory()->admin()->create();
        $h = CarbonImmutable::now()->startOfHour()->subHours(2);
        $this->seedHour($h, 2);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/analytics/rebuild', []);
        $res->assertOk();
        // The default window is now-24h, so the seed inside it must be picked up
        $this->assertGreaterThanOrEqual(1, $res->json('rebuilt_rows'));
    }
}
