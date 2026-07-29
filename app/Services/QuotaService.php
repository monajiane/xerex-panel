<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Subscription;
use App\Models\Usage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centralized quota / plan-limit enforcement.
 *
 *   - resolvePlan($user)         -> Plan the user is "on" (active/trialing sub, or default)
 *   - limit($user, $metric)      -> PlanLimit|null for the metric
 *   - usage($user, $metric)      -> current Usage row for the metric's current period
 *   - check($user, $metric, $delta) -> QuotaResult (allowed / not, remaining, is_soft, ...)
 *   - snapshot($user)            -> all metric rows (used/limit/remaining/pct)
 *
 *   Admins always have unlimited quotas.
 */
class QuotaService
{
    /**
     * Well-known metric names. We don't enforce these in code, but having
     * a canonical list helps when seeding default plans.
     */
    public const METRIC_DOMAINS     = 'domains';
    public const METRIC_EDGES       = 'edges';
    public const METRIC_ORIGINS     = 'origins';
    public const METRIC_PROXY_RULES = 'proxy_rules';
    public const METRIC_SSL_CERTS   = 'ssl_certs';
    public const METRIC_DNS_ZONES   = 'dns_zones';
    public const METRIC_TEAM_MEMBERS= 'team_members';
    public const METRIC_BANDWIDTH   = 'bandwidth_bytes';
    public const METRIC_REQUESTS    = 'requests';

    /**
     * Resolve the plan a user is "on" right now.
     * Priority: active/trialing subscription -> default plan -> null.
     */
    public function resolvePlan(User $user): ?Plan
    {
        $sub = $this->resolveSubscription($user);
        if ($sub && $sub->plan) {
            return $sub->plan;
        }
        return Plan::where('is_default', true)->where('is_active', true)->first()
            ?? Plan::where('is_active', true)->orderBy('sort_order')->first();
    }

    /**
     * The active subscription, or null if none.
     * A subscription is considered active if status is active/trialing AND
     * the current period end (or trial end) is in the future.
     */
    public function resolveSubscription(User $user): ?Subscription
    {
        $sub = $user->subscription()->with('plan')->first();
        if (! $sub) return null;
        if (! in_array($sub->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true)) {
            return null;
        }
        if ($sub->current_period_end && $sub->current_period_end->isPast()) {
            return null;
        }
        return $sub;
    }

    /**
     * Return the plan's limit for the given metric, or null if unrestricted.
     */
    public function limit(User $user, string $metric, string $period = 'lifetime'): ?PlanLimit
    {
        if ($user->is_admin) return null;
        $plan = $this->resolvePlan($user);
        if (! $plan) return null;
        return $plan->limitFor($metric, $period);
    }

    /**
     * Current usage for a metric, scoped to the period window the limit covers.
     * For period=lifetime we count *all* usage rows for the user/metric.
     * For period=month/day we pick the usage row whose window contains now().
     */
    public function usage(User $user, string $metric, string $period = 'lifetime'): Usage
    {
        $now = CarbonImmutable::now();
        $q = Usage::where('user_id', $user->id)->where('metric', $metric);

        if ($period === 'lifetime') {
            $row = $q->orderByDesc('period_start')->first();
            if ($row) return $row;
        } else {
            $row = $q->where('period_start', '<=', $now)
                ->where('period_end', '>', $now)
                ->first();
            if ($row) return $row;
        }

        // No row yet: return a transient in-memory Usage (not saved) so callers
        // can rely on a consistent shape.
        $period = $period === 'lifetime' ? 'month' : $period; // sensible default window
        [$start, $end] = $this->windowFor($period, $now);
        $u = new Usage([
            'user_id'     => $user->id,
            'metric'      => $metric,
            'quantity'    => 0,
            'period_start'=> $start,
            'period_end'  => $end,
        ]);
        $u->exists = true;
        return $u;
    }

    /**
     * Check whether the user can consume $delta more of $metric.
     */
    public function check(User $user, string $metric, int $delta = 1, string $period = 'lifetime'): QuotaResult
    {
        // Admins bypass quotas.
        if ($user->is_admin) {
            return QuotaResult::unlimited($metric, $delta);
        }

        $plan = $this->resolvePlan($user);
        $limit = $plan?->limitFor($metric, $period);

        // No limit defined -> treat as unlimited.
        if (! $limit || $limit->isUnlimited()) {
            return QuotaResult::unlimited($metric, $delta);
        }

        $used = $this->usage($user, $metric, $period)->quantity ?? 0;
        $remaining = max(0, $limit->value - $used);

        if ($delta <= $remaining) {
            return QuotaResult::allowed($metric, $used, $limit->value, $remaining, $limit->is_soft, $plan?->name);
        }
        return QuotaResult::denied($metric, $used, $limit->value, $remaining, $limit->is_soft, $plan?->name);
    }

    /**
     * Full snapshot of every metric defined on the user's plan, plus usage.
     * Returned as a Collection of associative arrays keyed by metric.
     */
    public function snapshot(User $user): Collection
    {
        $plan = $this->resolvePlan($user);
        if (! $plan) {
            return collect();
        }
        $rows = [];
        foreach ($plan->limits as $limit) {
            $used = $this->usage($user, $limit->metric, $limit->period)->quantity ?? 0;
            $rows[] = [
                'metric'      => $limit->metric,
                'period'      => $limit->period,
                'limit'       => $limit->value,
                'unlimited'   => $limit->isUnlimited(),
                'soft'        => $limit->is_soft,
                'used'        => (int) $used,
                'remaining'   => $limit->isUnlimited() ? null : max(0, $limit->value - (int) $used),
                'percent'     => $limit->isUnlimited() ? 0.0
                    : ($limit->value > 0 ? round(((int) $used) * 100 / $limit->value, 2) : 100.0),
            ];
        }
        return collect($rows)->sortBy('metric')->values();
    }

    /**
     * Window [start, end) for a period anchor.
     */
    public function windowFor(string $period, ?CarbonImmutable $anchor = null): array
    {
        $now = ($anchor ?? CarbonImmutable::now());
        return match ($period) {
            'hour'     => [$now->startOfHour(),     $now->startOfHour()->addHour()],
            'day'      => [$now->startOfDay(),      $now->startOfDay()->addDay()],
            'year'     => [$now->startOfYear(),     $now->startOfYear()->addYear()],
            'month'    => [$now->startOfMonth(),    $now->startOfMonth()->addMonth()],
            default    => [$now->startOfMonth(),    $now->startOfMonth()->addMonth()],
        };
    }
}
