<?php

namespace App\Services;

/**
 * Immutable result of a QuotaService::check() call.
 */
class QuotaResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $unlimited,
        public readonly string $metric,
        public readonly int $used,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly bool $soft,
        public readonly ?string $planName = null,
    ) {}

    public static function unlimited(string $metric, int $delta = 1): self
    {
        return new self(
            allowed: true,
            unlimited: true,
            metric: $metric,
            used: 0,
            limit: -1,
            remaining: PHP_INT_MAX,
            soft: false,
            planName: null,
        );
    }

    public static function allowed(string $metric, int $used, int $limit, int $remaining, bool $soft, ?string $plan): self
    {
        return new self(true, false, $metric, $used, $limit, $remaining, $soft, $plan);
    }

    public static function denied(string $metric, int $used, int $limit, int $remaining, bool $soft, ?string $plan): self
    {
        return new self(false, false, $metric, $used, $limit, $remaining, $soft, $plan);
    }

    public function isSoft(): bool
    {
        return $this->soft && $this->allowed;
    }

    /**
     * [used, limit, percent] for UI progress bars.
     */
    public function toArray(): array
    {
        return [
            'allowed'   => $this->allowed,
            'unlimited' => $this->unlimited,
            'metric'    => $this->metric,
            'used'      => $this->used,
            'limit'     => $this->limit,
            'remaining' => $this->unlimited ? null : $this->remaining,
            'soft'      => $this->soft,
            'plan'      => $this->planName,
        ];
    }
}
