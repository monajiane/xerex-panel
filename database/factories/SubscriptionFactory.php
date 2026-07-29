<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $now = now();
        $end = $now->copy()->addMonth();
        return [
            'uuid'                  => (string) Str::uuid(),
            'user_id'               => User::factory(),
            'plan_id'               => Plan::factory(),
            'status'                => Subscription::STATUS_ACTIVE,
            'trial_ends_at'         => null,
            'starts_at'             => $now,
            'current_period_start'  => $now,
            'current_period_end'    => $end,
            'cancel_at_period_end'  => false,
            'canceled_at'           => null,
            'ended_at'              => null,
            'meta'                  => [],
        ];
    }

    public function trialing(int $trialDays = 14): static
    {
        return $this->state(fn () => [
            'status'        => Subscription::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays($trialDays),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status'               => Subscription::STATUS_CANCELED,
            'cancel_at_period_end' => true,
            'canceled_at'          => now(),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(fn () => ['status' => Subscription::STATUS_PAST_DUE]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status'              => Subscription::STATUS_EXPIRED,
            'ended_at'            => now(),
            'current_period_end'  => now()->subDay(),
        ]);
    }
}
