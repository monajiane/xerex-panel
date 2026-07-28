<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\EdgeServer;
use App\Models\OriginServer;
use App\Models\ProxyRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProxyRule>
 */
class ProxyRuleFactory extends Factory
{
    protected $model = ProxyRule::class;

    public function definition(): array
    {
        return [
            'uuid'             => (string) Str::uuid(),
            'domain_id'        => Domain::factory(),
            'edge_server_id'   => EdgeServer::factory(),
            'origin_server_id' => OriginServer::factory(),
            'type'             => ProxyRule::TYPE_HTTP,
            'path'             => '/',
            'path_match_type'  => ProxyRule::PATH_PREFIX,
            'listen_port'      => 443,
            'force_https'      => false,
            'http2_enabled'    => true,
            'http3_enabled'    => false,
            'is_primary'       => true,
            'priority'         => 0,
            'weight'           => 1,
            'enabled'          => true,
            'cache_rules'      => [],
            'rate_limit'       => null,
            'access_rules'     => [],
            'headers_request'  => [],
            'headers_response' => [],
        ];
    }

    public function websocket(): static
    {
        return $this->state(fn () => [
            'type'        => ProxyRule::TYPE_WEBSOCKET,
            'listen_port' => 8443,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
