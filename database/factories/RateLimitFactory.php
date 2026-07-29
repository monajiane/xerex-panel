<?php

namespace Database\Factories;

use App\Models\RateLimit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RateLimit>
 */
class RateLimitFactory extends Factory
{
    protected $model = RateLimit::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true) . ' Limit';
        $max = fake()->randomElement([10, 50, 100, 500, 1000]);
        $window = fake()->randomElement([60, 300, 3600]);

        return [
            'uuid'            => (string) Str::uuid(),
            'name'            => ucwords($name),
            'slug'            => Str::slug($name) . '-' . Str::random(4),
            'description'     => fake()->sentence(),
            'scope_type'      => RateLimit::SCOPE_GLOBAL,
            'scope_id'        => null,
            'limit_type'      => RateLimit::LIMIT_IP,
            'max_requests'    => $max,
            'window_seconds'  => $window,
            'burst_multiplier'=> 1.0,
            'action'          => RateLimit::ACTION_BLOCK,
            'is_active'       => true,
            'metadata'        => [],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function perIp(): static
    {
        return $this->state(fn () => ['limit_type' => RateLimit::LIMIT_IP]);
    }

    public function perUser(): static
    {
        return $this->state(fn () => ['limit_type' => RateLimit::LIMIT_USER]);
    }

    public function perPath(): static
    {
        return $this->state(fn () => ['limit_type' => RateLimit::LIMIT_PATH]);
    }

    public function block(): static
    {
        return $this->state(fn () => ['action' => RateLimit::ACTION_BLOCK]);
    }

    public function challenge(): static
    {
        return $this->state(fn () => ['action' => RateLimit::ACTION_CHALLENGE]);
    }

    public function throttle(): static
    {
        return $this->state(fn () => ['action' => RateLimit::ACTION_THROTTLE]);
    }

    public function limits(int $max, int $windowSeconds): static
    {
        return $this->state(fn () => [
            'max_requests'   => $max,
            'window_seconds' => $windowSeconds,
        ]);
    }
}
