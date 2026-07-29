<?php

namespace Tests\Feature;

use App\Models\IpList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpListControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_index_returns_entries(): void
    {
        IpList::factory()->count(3)->create();
        $this->getJson('/api/security/ip-lists')
            ->assertOk()
            ->assertJsonCount(3, 'entries');
    }

    public function test_index_filters_by_type(): void
    {
        IpList::factory()->allow()->count(2)->create();
        IpList::factory()->block()->count(1)->create();
        $this->getJson('/api/security/ip-lists?list_type=allow')
            ->assertOk()
            ->assertJsonCount(2, 'entries');
    }

    public function test_store_creates_entry(): void
    {
        $this->postJson('/api/security/ip-lists', [
            'cidr'      => '1.2.3.4',
            'list_type' => 'block',
            'reason'    => 'Abuse',
        ])
            ->assertCreated()
            ->assertJsonPath('entry.cidr', '1.2.3.4/32');
    }

    public function test_store_rejects_invalid_cidr(): void
    {
        $this->postJson('/api/security/ip-lists', [
            'cidr'      => 'not-an-ip',
            'list_type' => 'block',
        ])->assertStatus(422);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->postJson('/api/security/ip-lists', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cidr', 'list_type']);
    }

    public function test_show_returns_entry(): void
    {
        $entry = IpList::factory()->create();
        $this->getJson("/api/security/ip-lists/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('entry.id', $entry->id);
    }

    public function test_update_modifies_entry(): void
    {
        $entry = IpList::factory()->block()->create(['reason' => 'Abuse']);
        $this->putJson("/api/security/ip-lists/{$entry->id}", [
            'reason' => 'Updated reason',
        ])->assertOk()->assertJsonPath('entry.reason', 'Updated reason');
    }

    public function test_destroy_removes_entry(): void
    {
        $entry = IpList::factory()->create();
        $this->deleteJson("/api/security/ip-lists/{$entry->id}")->assertNoContent();
        $this->assertDatabaseMissing('ip_lists', ['id' => $entry->id]);
    }

    public function test_bulk_import_creates_entries(): void
    {
        $this->postJson('/api/security/ip-lists/bulk', [
            'list_type' => 'block',
            'reason'    => 'Bulk import',
            'cidrs'     => "1.2.3.4\n5.6.7.0/24\n# comment line\nnot-an-ip",
        ])
            ->assertOk()
            ->assertJsonPath('created_count', 2)
            ->assertJsonPath('skipped_count', 1);
    }

    public function test_bulk_import_dedupes_existing(): void
    {
        IpList::factory()->block()->cidr('1.2.3.4/32')->create();
        $this->postJson('/api/security/ip-lists/bulk', [
            'list_type' => 'block',
            'cidrs'     => "1.2.3.4\n",
        ])->assertOk()->assertJsonPath('created_count', 1);
        $this->assertSame(1, IpList::where('cidr', '1.2.3.4/32')->count());
    }

    public function test_check_endpoint_detects_blocked_ip(): void
    {
        IpList::factory()->block()->cidr('1.2.3.4/32')->create();
        $this->postJson('/api/security/ip-lists/check', ['ip' => '1.2.3.4'])
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('block.cidr', '1.2.3.4/32');
    }

    public function test_check_endpoint_detects_allowed_ip(): void
    {
        IpList::factory()->allow()->cidr('1.2.3.4/32')->create();
        $this->postJson('/api/security/ip-lists/check', ['ip' => '1.2.3.4'])
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('blocked', false);
    }

    public function test_check_endpoint_detects_unknown_ip(): void
    {
        $this->postJson('/api/security/ip-lists/check', ['ip' => '9.9.9.9'])
            ->assertOk()
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('allowed', false);
    }

    public function test_check_validates_ip(): void
    {
        $this->postJson('/api/security/ip-lists/check', ['ip' => 'nope'])
            ->assertStatus(422);
    }

    public function test_unauthenticated_cannot_access(): void
    {
        auth()->forgetGuards();
        $this->getJson('/api/security/ip-lists')->assertStatus(401);
    }
}
