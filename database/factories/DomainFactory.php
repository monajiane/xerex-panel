<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $name = fake()->unique()->domainName();
        return [
            'uuid'      => (string) Str::uuid(),
            'user_id'   => User::factory(),
            'domain'    => $name,
            'root_path' => '/var/www/' . Str::slug($name),
            'doc_root'  => '/var/www/' . Str::slug($name) . '/public',
            'aliases'   => [],
            'php_version' => '8.3',
            'ssl_enabled' => false,
            'ssl_state'   => Domain::SSL_STATE_NONE,
            'is_active'   => true,
            'meta'        => [],
        ];
    }

    public function withSsl(): static
    {
        return $this->state(fn () => [
            'ssl_enabled' => true,
            'ssl_state'   => Domain::SSL_STATE_ACTIVE,
        ]);
    }
}
