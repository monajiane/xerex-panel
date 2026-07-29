<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\EdgeServer;
use App\Models\ProxyRule;
use App\Models\TrafficLog;
use App\Models\TrafficRollup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests the `xerex:traffic:rollup` and `xerex:traffic:prune` artisan commands.
 */
class TrafficCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_rollup_command_writes_one_row_per_bucket(): void
    {
        $edge = EdgeServer::factory()->create();
        $dom  = Domain::factory()->create();
        $rule = ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        $h1 = CarbonImmutable::parse('2026-04-01 10:00:00');
        $h2 = CarbonImmutable::parse('2026-04-01 11:00:00');
        TrafficLog::factory()
            ->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h1->addMinutes(10))->count(3)->create();
        TrafficLog::factory()
            ->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($h2->addMinutes(10))->count(7)->create();

        $this->artisan('xerex:traffic:rollup', [
            '--from' => $h1->toDateTimeString(),
            '--to'   => $h2->toDateTimeString(),
        ])->assertSuccessful();

        $this->assertSame(2, TrafficRollup::count());
        $this->assertSame(3, (int) TrafficRollup::where('bucket', $h1)->first()->requests);
        $this->assertSame(7, (int) TrafficRollup::where('bucket', $h2)->first()->requests);
    }

    public function test_rollup_command_full_day_option_rebuilds_yesterday(): void
    {
        $edge = EdgeServer::factory()->create();
        $dom  = Domain::factory()->create();
        $rule = ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        // 24 buckets across yesterday
        $yesterday = CarbonImmutable::yesterday()->startOfDay();
        for ($h = 0; $h < 24; $h++) {
            TrafficLog::factory()
                ->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
                ->at($yesterday->addHours($h)->addMinutes(15))
                ->count($h + 1) // unique count per hour
                ->create();
        }

        $this->artisan('xerex:traffic:rollup', ['--full-day' => true])
            ->expectsOutputToContain('Rebuilding 24 buckets')
            ->assertSuccessful();

        $this->assertSame(24, TrafficRollup::count());
    }

    public function test_rollup_command_default_targets_previous_hour(): void
    {
        $edge = EdgeServer::factory()->create();
        $dom  = Domain::factory()->create();
        $rule = ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        // seed 2 logs in the previous hour
        $prevHour = CarbonImmutable::now()->subHour()->startOfHour();
        TrafficLog::factory()
            ->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($prevHour->addMinutes(10))->count(2)->create();

        $this->artisan('xerex:traffic:rollup')
            ->assertSuccessful();

        $this->assertSame(1, TrafficRollup::count());
        $this->assertSame(2, (int) TrafficRollup::first()->requests);
    }

    public function test_prune_command_deletes_old_logs(): void
    {
        $edge = EdgeServer::factory()->create();
        $dom  = Domain::factory()->create();
        $rule = ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        // 3 old + 2 fresh
        $old = CarbonImmutable::now()->subDays(60);
        for ($i = 0; $i < 3; $i++) {
            TrafficLog::factory()
                ->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
                ->at($old->addMinutes($i))->create();
        }
        $fresh = CarbonImmutable::now()->subDays(2);
        for ($i = 0; $i < 2; $i++) {
            TrafficLog::factory()
                ->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
                ->at($fresh->addMinutes($i))->create();
        }

        $this->assertSame(5, TrafficLog::count());

        $this->artisan('xerex:traffic:prune', ['--days' => 30])
            ->assertSuccessful();

        $this->assertSame(2, TrafficLog::count(), 'kept the fresh logs');
    }

    public function test_prune_command_keeps_rollup_rows(): void
    {
        $edge = EdgeServer::factory()->create();
        $dom  = Domain::factory()->create();
        $rule = ProxyRule::factory()->create([
            'edge_server_id' => $edge->id,
            'domain_id'      => $dom->id,
        ]);

        $old = CarbonImmutable::now()->subDays(60);
        TrafficLog::factory()
            ->forEdge($edge->id)->forDomain($dom->id)->forRule($rule->id)
            ->at($old)->count(3)->create();

        // roll it up while the log is still in traffic_logs
        $oldBucket = $old->startOfHour();
        \Artisan::call('xerex:traffic:rollup', [
            '--from' => $oldBucket->toDateTimeString(),
            '--to'   => $oldBucket->toDateTimeString(),
        ]);
        $this->assertSame(1, TrafficRollup::count());

        $this->artisan('xerex:traffic:prune', ['--days' => 30])->assertSuccessful();

        $this->assertSame(0, TrafficLog::count());
        $this->assertSame(1, TrafficRollup::count(), 'rollups survive pruning');
    }

    public function test_prune_command_with_no_old_data_does_nothing(): void
    {
        $this->artisan('xerex:traffic:prune', ['--days' => 30])
            ->expectsOutputToContain('Done. Total deleted: 0')
            ->assertSuccessful();
    }
}
