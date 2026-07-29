<?php

namespace App\Services\Security;

use App\Models\RateLimit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Applies RateLimit policies to incoming requests.
 *
 * Strategy: a fixed-window counter per (policy, key) tuple. We use the
 * cache's atomic increment to ensure concurrent requests cannot race
 * past the limit. The first request in a window seeds the counter and
 * sets the TTL; subsequent hits only increment.
 *
 * For each scope+limit_type combination, the matching policy is loaded
 * and the request is bucketed accordingly. The middleware composes all
 * policies by OR-ing their decisions (i.e. any one that says "block"
 * blocks).
 */
class RateLimiter
{
    /** @var array<int, RateLimit>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly int $cacheTtlSeconds = 60,
    ) {}

    /**
     * Evaluate every active policy that applies to this request.
     * Returns the first blocking policy, or unlimited() when none match.
     */
    public function check(RateLimitRequest $request, ?string $scopeType = null, ?int $scopeId = null): RateLimitResult
    {
        foreach ($this->policies() as $policy) {
            if (!$this->policyApplies($policy, $scopeType, $scopeId)) {
                continue;
            }
            $key = $this->bucketKey($policy, $request);
            $result = $this->hit($policy, $key);
            if (!$result->allowed) {
                Log::info('ratelimit.exceeded', [
                    'policy'  => $policy->slug,
                    'key'     => $key,
                    'limit'   => $result->limit,
                    'current' => $result->current,
                ]);
                return $result;
            }
        }
        return RateLimitResult::unlimited();
    }

    /**
     * Inspect (count) without incrementing. Useful for the test endpoint.
     */
    public function inspect(RateLimitRequest $request, ?string $scopeType = null, ?int $scopeId = null): RateLimitResult
    {
        foreach ($this->policies() as $policy) {
            if (!$this->policyApplies($policy, $scopeType, $scopeId)) {
                continue;
            }
            $key   = $this->bucketKey($policy, $request);
            $count = (int) Cache::get($key, 0);
            return $count >= $policy->effectiveMax()
                ? RateLimitResult::exceeded($policy, $count, $this->retryAfter($policy, $key))
                : RateLimitResult::allowed($policy, $count);
        }
        return RateLimitResult::unlimited();
    }

    /**
     * Reset a single bucket (e.g. after admin override).
     */
    public function reset(string $policySlug, string $key): void
    {
        Cache::forget($this->cacheKey($policySlug, $key));
    }

    public function flushCache(): void
    {
        $this->cached = null;
        Cache::forget('ratelimit:policies:active');
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * @return array<int, RateLimit>
     */
    private function policies(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $cached = Cache::get('ratelimit:policies:active');
        if (is_array($cached)) {
            $this->cached = RateLimit::hydrate($cached);
        } else {
            $this->cached = RateLimit::query()
                ->active()
                ->orderBy('id')
                ->get()
                ->all();
            Cache::put('ratelimit:policies:active', array_map(fn ($p) => $p->toArray(), $this->cached), $this->cacheTtlSeconds);
        }
        return $this->cached;
    }

    private function policyApplies(RateLimit $policy, ?string $type, ?int $id): bool
    {
        if ($policy->scope_type === RateLimit::SCOPE_GLOBAL) {
            return true;
        }
        return $policy->scope_type === $type && (int) $policy->scope_id === (int) $id;
    }

    private function bucketKey(RateLimit $policy, RateLimitRequest $request): string
    {
        $bucket = match ($policy->limit_type) {
            RateLimit::LIMIT_IP     => $request->ip     ?? 'unknown',
            RateLimit::LIMIT_USER   => $request->userId ? 'u:' . $request->userId : 'anon',
            RateLimit::LIMIT_PATH   => strtolower($request->method) . ':' . ($request->path ?: '/'),
            RateLimit::LIMIT_DOMAIN => $request->domain ?? 'unknown',
            default                 => 'unknown',
        };
        return $this->cacheKey($policy->slug, $bucket);
    }

    private function cacheKey(string $policySlug, string $bucket): string
    {
        return "ratelimit:{$policySlug}:" . sha1($bucket);
    }

    private function hit(RateLimit $policy, string $key): RateLimitResult
    {
        $max  = $policy->effectiveMax();
        $ttl  = max(1, $policy->window_seconds);

        // Cache::add ensures the first request seeds without race.
        if (Cache::add($key, 1, $ttl)) {
            return RateLimitResult::allowed($policy, 1);
        }
        $count = (int) Cache::increment($key);
        if ($count > $max) {
            return RateLimitResult::exceeded($policy, $count, $this->retryAfter($policy, $key));
        }
        return RateLimitResult::allowed($policy, $count);
    }

    private function retryAfter(RateLimit $policy, string $key): int
    {
        // Cache TTL is the retry-after in seconds (approximate).
        $ttl = Cache::getRedis()->ttl($key) ?? $policy->window_seconds;
        return max(1, (int) $ttl);
    }
}
