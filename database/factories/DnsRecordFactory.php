<?php

namespace Database\Factories;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsRecord>
 */
class DnsRecordFactory extends Factory
{
    protected $model = DnsRecord::class;

    public function definition(): array
    {
        return [
            'zone_id' => DnsZone::factory(),
            'name'    => 'www',
            'type'    => 'A',
            'content' => fake()->ipv4(),
            'ttl'     => 3600,
            'priority'=> null,
            'weight'  => null,
            'port'    => null,
            'target'  => null,
            'is_managed' => true,
        ];
    }

    public function a(): static
    {
        return $this->state(fn () => ['type' => 'A', 'content' => fake()->ipv4()]);
    }

    public function aaaa(): static
    {
        return $this->state(fn () => ['type' => 'AAAA', 'content' => fake()->ipv6()]);
    }

    public function cname(): static
    {
        return $this->state(fn () => ['type' => 'CNAME', 'content' => fake()->domainName()]);
    }

    public function mx(): static
    {
        return $this->state(fn () => [
            'type'     => 'MX',
            'content'  => 'mail.' . fake()->domainName(),
            'priority' => 10,
        ]);
    }
}
