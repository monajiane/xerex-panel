<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\EdgeServer;
use App\Models\ProxyRule;
use App\Models\TrafficLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrafficLog>
 */
class TrafficLogFactory extends Factory
{
    protected $model = TrafficLog::class;

    public function definition(): array
    {
        $method = $this->faker->randomElement(['GET', 'GET', 'GET', 'POST', 'PUT', 'DELETE']);
        $code   = $this->faker->randomElement([200, 200, 200, 200, 200, 301, 304, 404, 500]);
        $cached = $this->faker->boolean(35);

        return [
            'domain_id'        => Domain::factory(),
            'edge_server_id'   => EdgeServer::factory(),
            'proxy_rule_id'    => ProxyRule::factory(),

            'method'  => $method,
            'scheme'  => $this->faker->randomElement(['http', 'https']),
            'url'     => $this->faker->url(),
            'host'    => $this->faker->domainName(),
            'path'    => '/' . $this->faker->word(),

            'response_code'    => $code,
            'bytes_sent'       => $this->faker->numberBetween(200, 1_500_000),
            'bytes_received'   => $this->faker->numberBetween(50,    50_000),
            'request_time_ms'  => $this->faker->numberBetween(1, 5000),
            'upstream_time_ms' => $this->faker->numberBetween(0, 4500),

            'client_ip'   => $this->faker->ipv4(),
            'user_agent'  => $this->faker->userAgent(),
            'referer'     => $this->faker->boolean(40) ? $this->faker->url() : null,

            'protocol'     => $this->faker->randomElement(['HTTP/1.1', 'HTTP/2', 'HTTP/3']),
            'cached'       => $cached,
            'cache_status' => $cached
                ? $this->faker->randomElement(['HIT', 'EXPIRED', 'STALE'])
                : $this->faker->randomElement(['MISS', 'BYPASS']),

            'logged_at' => now(),
        ];
    }

    /**
     * Pin the log to a specific wall-clock instant (used for rollup testing).
     */
    public function at(\DateTimeInterface|string $when): static
    {
        return $this->state(fn () => [
            'logged_at' => $when,
        ]);
    }

    public function cached(): static
    {
        return $this->state(fn () => [
            'cached'       => true,
            'cache_status' => 'HIT',
        ]);
    }

    public function uncached(): static
    {
        return $this->state(fn () => [
            'cached'       => false,
            'cache_status' => 'MISS',
        ]);
    }

    public function withStatus(int $code): static
    {
        return $this->state(fn () => ['response_code' => $code]);
    }

    public function forEdge(int $edgeId): static
    {
        return $this->state(fn () => ['edge_server_id' => $edgeId]);
    }

    public function forDomain(int $domainId): static
    {
        return $this->state(fn () => ['domain_id' => $domainId]);
    }

    public function forRule(int $ruleId): static
    {
        return $this->state(fn () => ['proxy_rule_id' => $ruleId]);
    }

    public function fromClient(string $ip): static
    {
        return $this->state(fn () => ['client_ip' => $ip]);
    }

    public function withBytes(int $in, int $out): static
    {
        return $this->state(fn () => [
            'bytes_received' => $in,
            'bytes_sent'     => $out,
        ]);
    }
}
