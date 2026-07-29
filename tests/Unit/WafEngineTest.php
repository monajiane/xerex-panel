<?php

namespace Tests\Unit;

use App\Models\WafRule;
use App\Services\Security\WafEngine;
use App\Services\Security\WafRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WafEngineTest extends TestCase
{
    use RefreshDatabase;

    protected WafEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(WafEngine::class);
    }

    public function test_returns_allow_when_no_rules(): void
    {
        $result = $this->engine->evaluate(new WafRequest('GET', '/'));
        $this->assertFalse($result->matched);
        $this->assertTrue($result->allowed ?? false);
    }

    public function test_ignores_inactive_rules(): void
    {
        WafRule::factory()->xss()->inactive()->create();
        $result = $this->engine->evaluate(new WafRequest('GET', '/page', query: 'a=<script>alert(1)</script>'));
        $this->assertFalse($result->matched);
    }

    public function test_blocks_xss_payload_in_uri(): void
    {
        WafRule::factory()->xss()->block()->create();
        $result = $this->engine->evaluate(new WafRequest('GET', '/page?a=<script>alert(1)</script>'));
        $this->assertTrue($result->matched);
        $this->assertSame('block', $result->action);
    }

    public function test_blocks_sql_injection_in_any_target(): void
    {
        WafRule::factory()->sqlInjection()->block()->create();
        $result = $this->engine->evaluate(new WafRequest(
            'GET', '/login', query: "username=admin' OR 1=1 --"
        ));
        $this->assertTrue($result->matched);
        $this->assertSame('block', $result->action);
    }

    public function test_blocks_path_traversal(): void
    {
        WafRule::factory()->create([
            'type' => WafRule::TYPE_PATH_TRAVERSAL,
            'pattern' => WafRule::presetPatterns()[WafRule::TYPE_PATH_TRAVERSAL],
            'target' => WafRule::TARGET_URI,
            'action' => WafRule::ACTION_BLOCK,
        ]);
        $result = $this->engine->evaluate(new WafRequest('GET', '/files/../../../etc/passwd'));
        $this->assertTrue($result->matched);
    }

    public function test_blocks_bad_user_agent(): void
    {
        WafRule::factory()->create([
            'type'        => WafRule::TYPE_USER_AGENT,
            'pattern'     => WafRule::presetPatterns()[WafRule::TYPE_USER_AGENT],
            'target'      => WafRule::TARGET_USER_AGENT,
            'action'      => WafRule::ACTION_CHALLENGE,
        ]);
        $result = $this->engine->evaluate(new WafRequest('GET', '/', userAgent: 'sqlmap/1.5'));
        $this->assertTrue($result->matched);
        $this->assertSame('challenge', $result->action);
    }

    public function test_challenge_action_is_blocking(): void
    {
        $result = new \App\Services\Security\WafResult(
            matched: true,
            rule: null,
            action: WafRule::ACTION_CHALLENGE,
        );
        $this->assertTrue($result->isBlocking());
    }

    public function test_allow_action_is_not_blocking(): void
    {
        $result = new \App\Services\Security\WafResult(
            matched: true,
            rule: null,
            action: WafRule::ACTION_ALLOW,
        );
        $this->assertFalse($result->isBlocking());
    }

    public function test_log_action_is_not_blocking_but_is_loggable(): void
    {
        $result = new \App\Services\Security\WafResult(
            matched: true,
            rule: null,
            action: WafRule::ACTION_LOG,
        );
        $this->assertFalse($result->isBlocking());
        $this->assertTrue($result->isLoggable());
    }

    public function test_priority_order_first_match_wins(): void
    {
        WafRule::factory()->xss()->block()->create(['priority' => 50, 'name' => 'Lower Pri XSS']);
        WafRule::factory()->create([
            'type'    => WafRule::TYPE_REGEX,
            'pattern' => '(?i)<script',
            'target'  => WafRule::TARGET_URI,
            'action'  => WafRule::ACTION_LOG,
            'priority'=> 200,
            'name'    => 'Higher Pri Log Script',
        ]);
        $result = $this->engine->evaluate(new WafRequest('GET', '/a?x=<script>'));
        $this->assertTrue($result->matched);
        // The higher-priority rule should have fired first.
        $this->assertSame('log', $result->action);
    }

    public function test_scope_matching_only_global(): void
    {
        WafRule::factory()->xss()->create([
            'scope_type' => WafRule::SCOPE_DOMAIN,
            'scope_id'   => 42,
        ]);
        // No scope provided → should not match
        $result = $this->engine->evaluate(new WafRequest('GET', '/a?x=<script>'));
        $this->assertFalse($result->matched);
    }

    public function test_scope_matching_with_matching_scope(): void
    {
        WafRule::factory()->xss()->create([
            'scope_type' => WafRule::SCOPE_DOMAIN,
            'scope_id'   => 42,
        ]);
        $result = $this->engine->evaluate(
            new WafRequest('GET', '/a?x=<script>'),
            WafRule::SCOPE_DOMAIN,
            42,
        );
        $this->assertTrue($result->matched);
    }

    public function test_evaluate_all_returns_every_match(): void
    {
        WafRule::factory()->xss()->block()->create();
        WafRule::factory()->sqlInjection()->log()->create();
        $matches = $this->engine->evaluateAll(
            new WafRequest('GET', '/a?x=<script>foo\' OR 1=1')
        );
        $this->assertGreaterThanOrEqual(2, count($matches));
    }

    public function test_invalid_regex_is_skipped_silently(): void
    {
        WafRule::factory()->create([
            'type'    => WafRule::TYPE_REGEX,
            'pattern' => '(unclosed',       // broken regex
            'target'  => WafRule::TARGET_URI,
            'action'  => WafRule::ACTION_BLOCK,
        ]);
        // Should not throw; should return allow
        $result = $this->engine->evaluate(new WafRequest('GET', '/'));
        $this->assertFalse($result->matched);
    }
}
