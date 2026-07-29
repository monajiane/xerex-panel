<?php

namespace Database\Factories;

use App\Models\IpList;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IpList>
 */
class IpListFactory extends Factory
{
    protected $model = IpList::class;

    public function definition(): array
    {
        return [
            'uuid'       => (string) Str::uuid(),
            'cidr'       => fake()->ipv4() . '/32',
            'list_type'  => fake()->randomElement([IpList::TYPE_ALLOW, IpList::TYPE_BLOCK]),
            'reason'     => fake()->sentence(),
            'source'     => 'manual',
            'scope_type' => null,
            'scope_id'   => null,
            'expires_at' => null,
            'created_by' => null,
        ];
    }

    public function allow(): static
    {
        return $this->state(fn () => ['list_type' => IpList::TYPE_ALLOW]);
    }

    public function block(): static
    {
        return $this->state(fn () => ['list_type' => IpList::TYPE_BLOCK]);
    }

    public function cidr(string $cidr): static
    {
        return $this->state(fn () => ['cidr' => $cidr]);
    }

    public function expiresIn(int $seconds): static
    {
        return $this->state(fn () => ['expires_at' => now()->addSeconds($seconds)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }

    public function ipv4(): static
    {
        return $this->state(fn () => ['cidr' => fake()->ipv4() . '/32']);
    }

    public function ipv6(): static
    {
        return $this->state(fn () => ['cidr' => fake()->ipv6() . '/128']);
    }
}
