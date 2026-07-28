<?php

namespace Database\Factories;

use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DnsZone>
 */
class DnsZoneFactory extends Factory
{
    protected $model = DnsZone::class;

    public function definition(): array
    {
        $name = fake()->unique()->domainName();
        return [
            'uuid'         => (string) Str::uuid(),
            'user_id'      => User::factory(),
            'name'         => $name,
            'type'         => 'master',
            'kind'         => 'native',
            'dnssec'       => false,
            'serial'       => 1,
            'ttl'          => 3600,
            'primary_ns'   => 'ns1.xerex.local',
            'admin_email'  => 'hostmaster.' . $name,
            'refresh'      => 10800,
            'retry'        => 3600,
            'expire'       => 604800,
            'minimum'      => 3600,
            'powerdns_id'  => $name,
            'is_active'    => true,
            'meta'         => [],
        ];
    }
}
