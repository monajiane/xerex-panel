<?php

namespace Database\Factories;

use App\Models\EdgeServer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EdgeServer>
 */
class EdgeServerFactory extends Factory
{
    protected $model = EdgeServer::class;

    public function definition(): array
    {
        return [
            'uuid'         => (string) Str::uuid(),
            'name'         => 'edge-' . fake()->unique()->word(),
            'hostname'     => fake()->domainName(),
            'ip_address'   => fake()->ipv4(),
            'location'     => fake()->city(),
            'country_code' => fake()->countryCode(),
            'region'       => 'us-east',
            'datacenter'   => 'dc1',
            'status'       => EdgeServer::STATUS_PROVISIONING,
            'agent_token'  => 'xerx_' . Str::random(48),
            'capabilities' => ['http', 'https', 'websocket'],
            'meta'         => [],
            'cpu_cores'    => 4,
            'ram_mb'       => 8192,
            'disk_gb'      => 100,
            'bandwidth_mbps'=> 1000,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'status'       => EdgeServer::STATUS_ONLINE,
            'last_seen_at' => now(),
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['status' => EdgeServer::STATUS_OFFLINE]);
    }

    public function degraded(): static
    {
        return $this->state(fn () => ['status' => EdgeServer::STATUS_DEGRADED]);
    }
}
