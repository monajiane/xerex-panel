<?php

namespace App\Services;

use App\Models\Usage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record metered usage (bandwidth, request counts, etc.) per (user, metric, period).
 *
 * The increment is done in a single atomic UPSERT so the read-modify-write
 * race condition is impossible. We rely on the (user_id, metric, period_start)
 * unique index to make the operation conflict-free.
 */
class UsageMeter
{
    public function __construct(private readonly QuotaService $quotas) {}

    /**
     * Increment a counter by $delta (default 1). Creates the row on first use.
     *
     * @return Usage  the row that now reflects the new quantity
     */
    public function record(User $user, string $metric, int $delta = 1, string $period = 'month'): Usage
    {
        if ($delta === 0) {
            return $this->quotas->usage($user, $metric, $period);
        }

        [$start, $end] = $this->quotas->windowFor($period);

        $now = now();
        DB::statement(
            'INSERT INTO usages (user_id, metric, quantity, period_start, period_end, last_incremented_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id, metric, period_start)
             DO UPDATE SET quantity = usages.quantity + EXCLUDED.quantity,
                           last_incremented_at = EXCLUDED.last_incremented_at,
                           updated_at = EXCLUDED.updated_at',
            [$user->id, $metric, $delta, $start, $end, $now, $now, $now],
        );

        return Usage::where('user_id', $user->id)
            ->where('metric', $metric)
            ->where('period_start', $start)
            ->firstOrFail();
    }

    /**
     * Decrement (refund) a counter. Clamped at zero.
     */
    public function refund(User $user, string $metric, int $delta = 1, string $period = 'month'): Usage
    {
        if ($delta === 0) {
            return $this->quotas->usage($user, $metric, $period);
        }
        $usage = $this->quotas->usage($user, $metric, $period);
        if (! $usage || $usage->quantity === 0) {
            return $usage;
        }
        $newQty = max(0, $usage->quantity - $delta);
        $usage->update(['quantity' => $newQty, 'last_incremented_at' => now()]);
        return $usage->fresh();
    }

    /**
     * Idempotently record an absolute value (e.g. "we just set the count to 7").
     * Useful when ingesting from external sources (ClickHouse, edge reports, ...).
     */
    public function setAbsolute(User $user, string $metric, int $quantity, string $period = 'month'): Usage
    {
        [$start, $end] = $this->quotas->windowFor($period);
        $now = now();
        return Usage::updateOrCreate(
            ['user_id' => $user->id, 'metric' => $metric, 'period_start' => $start],
            ['quantity' => $quantity, 'period_end' => $end, 'last_incremented_at' => $now],
        );
    }

    /**
     * Snapshot the current counter without writing anything.
     */
    public function peek(User $user, string $metric, string $period = 'month'): int
    {
        return (int) $this->quotas->usage($user, $metric, $period)->quantity;
    }
}
