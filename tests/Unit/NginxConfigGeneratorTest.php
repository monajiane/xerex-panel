<?php

namespace Tests\Unit;

use App\Models\Domain;
use App\Models\EdgeServer;
use App\Models\OriginServer;
use App\Models\ProxyRule;
use App\Services\NginxConfigGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for NginxConfigGenerator (no database needed).
 */
class NginxConfigGeneratorTest extends TestCase
{
    public function test_generates_http_block_for_simple_rule(): void
    {
        $domain = $this->makeDomain('example.com');
        $origin = $this->makeOrigin('upstream.example.com', 8080);
        $rule = $this->makeRule($domain, $origin, [
            'type' => ProxyRule::TYPE_HTTP,
            'listen_port' => 443,
            'path' => '/',
            'http2_enabled' => true,
        ]);

        $config = (new NginxConfigGenerator)->generate($rule);

        $this->assertStringContainsString('server {', $config);
        $this->assertStringContainsString('listen 443 ssl http2 on;', $config);
        $this->assertStringContainsString('server_name example.com;', $config);
        $this->assertStringContainsString('upstream upstream_1 {', $config);
        $this->assertStringContainsString('server upstream.example.com:8080', $config);
        $this->assertStringContainsString('proxy_pass http://upstream_1;', $config);
    }

    public function test_websocket_rule_includes_upgrade_headers(): void
    {
        $domain = $this->makeDomain('ws.example.com');
        $origin = $this->makeOrigin('backend.example.com', 8080, https: true);
        $rule = $this->makeRule($domain, $origin, [
            'type' => ProxyRule::TYPE_WEBSOCKET,
            'listen_port' => 8443,
            'path' => '/ws',
        ]);

        $config = (new NginxConfigGenerator)->generate($rule);

        $this->assertStringContainsString('proxy_http_version 1.1;', $config);
        $this->assertStringContainsString('proxy_set_header Upgrade', $config);
        $this->assertStringContainsString('proxy_set_header Connection "upgrade"', $config);
        $this->assertStringContainsString('proxy_read_timeout 3600s;', $config);
    }

    public function test_redirect_rule_returns_301(): void
    {
        $domain = $this->makeDomain('old.example.com');
        $origin = $this->makeOrigin('ignored', 80);
        $rule = $this->makeRule($domain, $origin, [
            'type' => ProxyRule::TYPE_REDIRECT,
            'listen_port' => 80,
            'headers_request' => ['redirect_to' => 'https://new.example.com'],
        ]);

        $config = (new NginxConfigGenerator)->generate($rule);

        $this->assertStringContainsString('return 301 https://new.example.com;', $config);
    }

    public function test_tcp_rule_uses_stream_block(): void
    {
        $domain = $this->makeDomain('tcp.example.com');
        $origin = $this->makeOrigin('tcp-backend.example.com', 5432, protocol: 'tcp');
        $rule = $this->makeRule($domain, $origin, [
            'type' => ProxyRule::TYPE_TCP,
            'listen_port' => 5432,
        ]);

        $config = (new NginxConfigGenerator)->generate($rule);

        $this->assertStringContainsString('stream {', $config);
        $this->assertStringContainsString('proxy_pass tcp-backend.example.com:5432;', $config);
    }

    public function test_grpc_rule_uses_grpc_pass(): void
    {
        $domain = $this->makeDomain('grpc.example.com');
        $origin = $this->makeOrigin('grpc-backend.example.com', 50051, protocol: 'grpc');
        $rule = $this->makeRule($domain, $origin, [
            'type' => ProxyRule::TYPE_GRPC,
            'listen_port' => 50051,
        ]);

        $config = (new NginxConfigGenerator)->generate($rule);

        $this->assertStringContainsString('grpc_pass http://grpc-backend.example.com:50051;', $config);
    }

    /* ---------- helpers ---------- */

    protected function makeDomain(string $name): Domain
    {
        $d = new Domain();
        $d->domain = $name;
        $d->id = 1;
        return $d;
    }

    protected function makeOrigin(string $host, int $port, bool $https = false, string $protocol = 'http'): OriginServer
    {
        $o = new OriginServer();
        $o->id = 1;
        $o->host = $host;
        $o->port = $port;
        $o->protocol = $https ? 'https' : $protocol;
        $o->ssl_enabled = $https;
        $o->weight = 1;
        $o->max_fails = 3;
        $o->fail_timeout = 10;
        $o->connect_timeout = 5;
        $o->send_timeout = 30;
        $o->read_timeout = 30;
        $o->failover_group = null;
        $o->is_active = true;
        return $o;
    }

    protected function makeRule(Domain $domain, OriginServer $origin, array $attrs): ProxyRule
    {
        $r = new ProxyRule();
        $r->id = 1;
        $r->setRelations([
            'domain' => $domain,
            'originServer' => $origin,
        ]);
        $r->type = $attrs['type'] ?? ProxyRule::TYPE_HTTP;
        $r->listen_port = $attrs['listen_port'] ?? 443;
        $r->path = $attrs['path'] ?? '/';
        $r->path_match_type = $attrs['path_match_type'] ?? ProxyRule::PATH_PREFIX;
        $r->http2_enabled = $attrs['http2_enabled'] ?? false;
        $r->http3_enabled = $attrs['http3_enabled'] ?? false;
        $r->force_https = $attrs['force_https'] ?? false;
        $r->headers_request = $attrs['headers_request'] ?? [];
        $r->headers_response = $attrs['headers_response'] ?? [];
        $r->cache_rules = [];
        $r->rate_limit = null;
        $r->access_rules = [];
        return $r;
    }
}
