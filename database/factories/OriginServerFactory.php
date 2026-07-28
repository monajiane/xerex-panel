<?php

namespace Database\Factories;

use App\Models\OriginServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OriginServer>
 */
class OriginServerFactory extends Factory
{
    protected $model = OriginServer::class;

    public function definition(): array
    {
        return [
            'uuid'         => (string) Str::uuid(),
            'user_id'      => User::factory(),
            'name'         => 'origin-' . fake()->unique()->word(),
            'host'         => fake()->domainName(),
            'port'         => 80,
            'protocol'     => OriginServer::PROTOCOL_HTTP,
            'upstream_type'=> 'web',
            'ssl_enabled'  => false,
            'ssl_verify'   => true,
            'weight'       => 1,
            'max_fails'    => 3,
            'fail_timeout' => 10,
            'health_check_enabled'       => true,
            'health_check_path'          => '/health',
            'health_check_interval'      => 30,
            'health_check_timeout'       => 5,
            'health_check_expected_status' => 200,
            'health_status'    => OriginServer::HEALTH_UNKNOWN,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'max_connections' => 0,
            'connect_timeout' => 5,
            'read_timeout'    => 30,
            'send_timeout'    => 30,
            'headers'         => [],
            'meta'            => [],
            'is_active'       => true,
            'failover_group'  => null,
            'failover_priority' => 0,
        ];
    }

    public function inGroup(string $group, int $priority = 0): static
    {
        return $this->state(fn () => [
            'failover_group'    => $group,
            'failover_priority' => $priority,
        ]);
    }

    public function healthy(): static
    {
        return $this->state(fn () => [
            'health_status'          => OriginServer::HEALTH_UP,
            'consecutive_failures'   => 0,
            'consecutive_successes'  => 3,
            'last_health_check_at'   => now(),
        ]);
    }

    public function down(int $failures = 5): static
    {
        return $this->state(fn () => [
            'health_status'        => OriginServer::HEALTH_DOWN,
            'consecutive_failures' => $failures,
            'is_active'            => false,
        ]);
    }

    public function https(): static
    {
        return $this->state(fn () => [
            'protocol'    => OriginServer::PROTOCOL_HTTPS,
            'port'        => 443,
            'ssl_enabled' => true,
        ]);
    }
}
