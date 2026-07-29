<?php

namespace Tests\Unit;

use App\Models\IpList;
use App\Services\Security\IpListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpListServiceTest extends TestCase
{
    use RefreshDatabase;

    protected IpListService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(IpListService::class);
    }

    public function test_normalise_cidr_pads_v4(): void
    {
        $this->assertSame('1.2.3.4/32', $this->svc->normaliseCidr('1.2.3.4'));
    }

    public function test_normalise_cidr_pads_v6(): void
    {
        $cidr = $this->svc->normaliseCidr('::1');
        $this->assertMatchesRegularExpression('#::1/128$#', (string) $cidr);
    }

    public function test_normalise_cidr_rejects_invalid(): void
    {
        $this->assertNull($this->svc->normaliseCidr('not.an.ip'));
        $this->assertNull($this->svc->normaliseCidr('1.2.3.4/40'));
        $this->assertNull($this->svc->normaliseCidr(''));
    }

    public function test_is_blocked_returns_null_when_empty(): void
    {
        $this->assertNull($this->svc->isBlocked('1.2.3.4'));
    }

    public function test_is_blocked_returns_match_for_exact_ip(): void
    {
        IpList::factory()->block()->cidr('1.2.3.4/32')->create();
        $match = $this->svc->isBlocked('1.2.3.4');
        $this->assertNotNull($match);
        $this->assertSame('1.2.3.4/32', $match->cidr);
    }

    public function test_is_blocked_matches_cidr_range(): void
    {
        IpList::factory()->block()->cidr('10.0.0.0/8')->create();
        $this->assertNotNull($this->svc->isBlocked('10.5.6.7'));
        $this->assertNotNull($this->svc->isBlocked('10.255.255.255'));
        $this->assertNull($this->svc->isBlocked('11.0.0.1'));
    }

    public function test_expired_entries_are_ignored(): void
    {
        IpList::factory()->block()->cidr('1.2.3.4/32')->expired()->create();
        $this->assertNull($this->svc->isBlocked('1.2.3.4'));
    }

    public function test_allow_overrides_block(): void
    {
        IpList::factory()->block()->cidr('10.0.0.0/8')->create();
        IpList::factory()->allow()->cidr('10.1.2.3/32')->create();
        $this->assertNull($this->svc->isBlocked('10.1.2.3'));
        $this->assertTrue($this->svc->isAllowed('10.1.2.3'));
        // IP outside the allow range is still blocked
        $this->assertNotNull($this->svc->isBlocked('10.9.9.9'));
    }

    public function test_invalid_ip_returns_null(): void
    {
        IpList::factory()->block()->cidr('1.2.3.4/32')->create();
        $this->assertNull($this->svc->isBlocked('not-an-ip'));
    }

    public function test_ipv6_matching(): void
    {
        IpList::factory()->block()->cidr('2001:db8::/32')->create();
        $this->assertNotNull($this->svc->isBlocked('2001:db8::1'));
        $this->assertNotNull($this->svc->isBlocked('2001:db8:abcd::1234'));
        $this->assertNull($this->svc->isBlocked('2001:db9::1'));
    }

    public function test_scope_match_domain(): void
    {
        IpList::factory()->block()->cidr('1.2.3.4/32')->create([
            'scope_type' => IpList::SCOPE_DOMAIN,
            'scope_id'   => 99,
        ]);
        // Wrong scope → not blocked
        $this->assertNull($this->svc->isBlocked('1.2.3.4', IpList::SCOPE_DOMAIN, 100));
        // Right scope → blocked
        $this->assertNotNull($this->svc->isBlocked('1.2.3.4', IpList::SCOPE_DOMAIN, 99));
    }

    public function test_global_rule_applies_to_all_scopes(): void
    {
        IpList::factory()->block()->cidr('1.2.3.4/32')->create();
        $this->assertNotNull($this->svc->isBlocked('1.2.3.4', IpList::SCOPE_DOMAIN, 100));
        $this->assertNotNull($this->svc->isBlocked('1.2.3.4', IpList::SCOPE_EDGE, 5));
    }
}
