<?php

namespace App\Services\Security;

use App\Models\RateLimit;

/**
 * Immutable value object describing the outcome of a rate-limit check.
 *
 * `allowed` is true when the request is within the policy's threshold.
 * `limit` / `current` are the configured ceiling and the counter value
 * AFTER this hit was counted.
 * `retryAfter` is the number of seconds the caller should wait before
 * retrying (0 when allowed).
 */
class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?RateLimit $policy = null,
        public readonly int $limit = 0,
        public readonly int $current = 0,
        public readonly int $retryAfter = 0,
        public readonly string $action = RateLimit::ACTION_BLOCK,
    ) {}

    public static function unlimited(): self
    {
        return new self(true, null, 0, 0, 0, RateLimit::ACTION_LOG);
    }

    public static function exceeded(RateLimit $policy, int $current, int $retryAfter): self
    {
        return new self(false, $policy, $policy->effectiveMax(), $current, $retryAfter, $policy->action);
    }

    public static function allowed(RateLimit $policy, int $current): self
    {
        return new self(true, $policy, $policy->effectiveMax(), $current, 0, $policy->action);
    }

    public function toArray(): array
    {
        return [
            'allowed'     => $this->allowed,
            'action'      => $this->action,
            'limit'       => $this->limit,
            'current'     => $this->current,
            'retry_after' => $this->retryAfter,
            'policy'      => $this->policy ? [
                'id'   => $this->policy->id,
                'uuid' => $this->policy->uuid,
                'name' => $this->policy->name,
                'slug' => $this->policy->slug,
            ] : null,
        ];
    }
}
