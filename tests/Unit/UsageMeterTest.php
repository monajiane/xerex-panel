<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Usage;
use App\Models\User;
use App\Services\BillingService;
use App\Services\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageMeterTest extends TestCase
{
    use RefreshDatabase;

    protected UsageMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = app(UsageMeter::class);
    }

    public function test_record_creates_first_row(): void
    {
        $user = User::factory()->create();
        $row = $this->meter->record($user, 'domains', 1);
        $this->assertSame(1, $row->quantity);
        $this->assertSame(1, Usage::count());
    }

    public function test_record_increments_existing_row(): void
    {
        $user = User::factory()->create();
        $this->meter->record($user, 'bandwidth_bytes', 100);
        $this->meter->record($user, 'bandwidth_bytes', 250);
        $this->meter->record($user, 'bandwidth_bytes', 50);

        $row = Usage::where('user_id', $user->id)->where('metric', 'bandwidth_bytes')->first();
        $this->assertSame(400, $row->quantity);
        $this->assertSame(1, Usage::count());
    }

    public function test_record_with_zero_delta_is_no_op(): void
    {
        $user = User::factory()->create();
        $row = $this->meter->record($user, 'requests', 0);
        $this->assertSame(0, $row->quantity);
        $this->assertSame(0, Usage::count());
    }

    public function test_refund_decrements_clamped_at_zero(): void
    {
        $user = User::factory()->create();
        $this->meter->record($user, 'requests', 5);
        $row = $this->meter->refund($user, 'requests', 2);
        $this->assertSame(3, $row->fresh()->quantity);

        $row2 = $this->meter->refund($user, 'requests', 100);
        $this->assertSame(0, $row2->fresh()->quantity);
    }

    public function test_refund_on_empty_does_nothing(): void
    {
        $user = User::factory()->create();
        $row = $this->meter->refund($user, 'requests', 1);
        $this->assertSame(0, $row->quantity);
    }

    public function test_set_absolute_replaces_quantity(): void
    {
        $user = User::factory()->create();
        $this->meter->setAbsolute($user, 'bandwidth_bytes', 1000);
        $this->meter->setAbsolute($user, 'bandwidth_bytes', 2500);
        $row = Usage::where('user_id', $user->id)->where('metric', 'bandwidth_bytes')->first();
        $this->assertSame(2500, $row->quantity);
    }

    public function test_peek_returns_current_quantity(): void
    {
        $user = User::factory()->create();
        $this->meter->record($user, 'requests', 7);
        $this->assertSame(7, $this->meter->peek($user, 'requests'));
        $this->assertSame(0, $this->meter->peek($user, 'domains'));
    }
}
