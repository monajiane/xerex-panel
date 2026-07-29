<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->unique()->word() . ' Plan';
        return [
            'uuid'           => (string) Str::uuid(),
            'slug'           => Str::slug($name) . '-' . Str::random(4),
            'name'           => ucfirst($name),
            'tagline'        => fake()->sentence(),
            'description'    => fake()->paragraph(),
            'price_cents'    => fake()->randomElement([0, 900, 1900, 4900, 9900]),
            'currency'       => 'USD',
            'billing_period' => 'month',
            'is_active'      => true,
            'is_public'      => true,
            'is_default'     => false,
            'trial_days'     => 0,
            'sort_order'     => 100,
            'features'       => ['support' => 'email', 'sla' => '99.9%'],
            'meta'           => [],
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => [
            'slug'        => 'free-' . Str::random(4),
            'name'        => 'Free',
            'price_cents' => 0,
            'trial_days'  => 0,
            'sort_order'  => 10,
        ]);
    }

    public function pro(): static
    {
        return $this->state(fn () => [
            'slug'        => 'pro-' . Str::random(4),
            'name'        => 'Pro',
            'price_cents' => 1900,
            'trial_days'  => 14,
            'sort_order'  => 20,
        ]);
    }

    public function business(): static
    {
        return $this->state(fn () => [
            'slug'        => 'business-' . Str::random(4),
            'name'        => 'Business',
            'price_cents' => 4900,
            'trial_days'  => 14,
            'sort_order'  => 30,
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn () => [
            'slug'        => 'enterprise-' . Str::random(4),
            'name'        => 'Enterprise',
            'price_cents' => 19900,
            'trial_days'  => 30,
            'sort_order'  => 40,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
