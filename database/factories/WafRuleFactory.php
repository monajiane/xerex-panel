<?php

namespace Database\Factories;

use App\Models\WafRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WafRule>
 */
class WafRuleFactory extends Factory
{
    protected $model = WafRule::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true) . ' Rule';
        $type = fake()->randomElement([
            WafRule::TYPE_SQL_INJECTION,
            WafRule::TYPE_XSS,
            WafRule::TYPE_PATH_TRAVERSAL,
            WafRule::TYPE_USER_AGENT,
            WafRule::TYPE_REGEX,
        ]);

        return [
            'uuid'         => (string) Str::uuid(),
            'name'         => ucwords($name),
            'slug'         => Str::slug($name) . '-' . Str::random(4),
            'description'  => fake()->sentence(),
            'type'         => $type,
            'pattern'      => WafRule::presetPatterns()[$type] ?? '(?i)malicious',
            'target'       => WafRule::TARGET_URI,
            'target_field' => null,
            'action'       => WafRule::ACTION_BLOCK,
            'priority'     => fake()->numberBetween(50, 200),
            'is_active'    => true,
            'scope_type'   => null,
            'scope_id'     => null,
            'metadata'     => [],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function block(): static
    {
        return $this->state(fn () => ['action' => WafRule::ACTION_BLOCK]);
    }

    public function log(): static
    {
        return $this->state(fn () => ['action' => WafRule::ACTION_LOG]);
    }

    public function challenge(): static
    {
        return $this->state(fn () => ['action' => WafRule::ACTION_CHALLENGE]);
    }

    public function regex(string $pattern): static
    {
        return $this->state(fn () => [
            'type'    => WafRule::TYPE_REGEX,
            'pattern' => $pattern,
        ]);
    }

    public function xss(): static
    {
        return $this->state(fn () => [
            'type'    => WafRule::TYPE_XSS,
            'pattern' => WafRule::presetPatterns()[WafRule::TYPE_XSS],
        ]);
    }

    public function sqlInjection(): static
    {
        return $this->state(fn () => [
            'type'    => WafRule::TYPE_SQL_INJECTION,
            'pattern' => WafRule::presetPatterns()[WafRule::TYPE_SQL_INJECTION],
        ]);
    }
}
