<?php

namespace App\Services\Security;

use App\Models\WafRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Evaluates a WafRequest against the active WafRule set.
 *
 * The rule set is loaded once per request lifecycle and cached in-memory
 * (per process) for the configured TTL (default 60s). Reload is forced
 * when WafRule::saved / WafRule::deleted events fire.
 *
 * Rules are evaluated in priority order (highest first). The first
 * blocking or challenging action short-circuits the loop; "log" actions
 * are still returned as matched so the audit log captures them.
 */
class WafEngine
{
    /** @var array<int, WafRule>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly int $cacheTtlSeconds = 60,
    ) {}

    /**
     * Evaluate a request, return the first match.
     */
    public function evaluate(WafRequest $request, ?string $scopeType = null, ?int $scopeId = null): WafResult
    {
        $rules = $this->rulesFor($scopeType, $scopeId);
        $evaluated = 0;

        foreach ($rules as $rule) {
            $evaluated++;
            $evidence = $this->matchRule($rule, $request);
            if ($evidence !== null) {
                $this->logMatch($rule, $request, $evidence);
                return WafResult::match($rule, $evidence, $evaluated);
            }
        }

        return WafResult::allow($evaluated);
    }

    /**
     * Test every rule against a request and return all matches (no short-circuit).
     * Used by the API test endpoint.
     *
     * @return array<int, array{rule: WafRule, evidence: ?string}>
     */
    public function evaluateAll(WafRequest $request, ?string $scopeType = null, ?int $scopeId = null): array
    {
        $matches = [];
        foreach ($this->rulesFor($scopeType, $scopeId) as $rule) {
            $evidence = $this->matchRule($rule, $request);
            if ($evidence !== null) {
                $matches[] = ['rule' => $rule, 'evidence' => $evidence];
            }
        }
        return $matches;
    }

    /**
     * Force a fresh reload of the rule cache. Call after editing rules.
     */
    public function flushCache(): void
    {
        $this->cache = null;
        Cache::forget('waf:rules:active');
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * @return array<int, WafRule>
     */
    private function rulesFor(?string $scopeType, ?int $scopeId): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $key = 'waf:rules:active';
        $cached = Cache::get($key);
        if (is_array($cached)) {
            // Re-hydrate (cache stores arrays to avoid serialising models)
            $rules = WafRule::hydrate($cached);
        } else {
            $rules = WafRule::query()
                ->active()
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->all();

            Cache::put($key, array_map(fn (WafRule $r) => $r->toArray(), $rules), $this->cacheTtlSeconds);
        }

        // Filter to rules that match the requested scope.
        $this->cache = array_values(array_filter(
            $rules,
            fn (WafRule $r) => $r->appliesTo($scopeType, $scopeId)
        ));

        return $this->cache;
    }

    /**
     * Match a single rule. Returns the matched substring (or null).
     */
    private function matchRule(WafRule $rule, WafRequest $request): ?string
    {
        $haystack = $this->extractTarget($rule, $request);
        if ($haystack === null || $haystack === '') {
            return null;
        }

        // Built-in types just compile to regex once via presetPatterns().
        $pattern = $rule->pattern;

        // Build a safe delimiter; "/" would need escaping inside pattern.
        $delim = '#';
        // Make sure pattern doesn't contain the delimiter
        if (str_contains($pattern, $delim)) {
            $delim = '@';
        }
        $regex = $delim . $pattern . $delim . 'iu';

        // preg_match returns 1 on match, 0 on no match, false on error.
        $matched = @preg_match($regex, $haystack, $m);
        if ($matched === 1) {
            return $m[0] ?? '1';
        }
        if ($matched === false) {
            Log::warning('WAF rule has invalid regex', [
                'rule_id' => $rule->id,
                'slug'    => $rule->slug,
                'pattern' => $pattern,
                'error'   => preg_last_error_msg(),
            ]);
        }
        return null;
    }

    /**
     * Pick the part of the request the rule targets.
     */
    private function extractTarget(WafRule $rule, WafRequest $request): ?string
    {
        return match ($rule->target) {
            WafRule::TARGET_URI        => $request->uri,
            WafRule::TARGET_QUERY      => $request->query,
            WafRule::TARGET_BODY       => $request->body,
            WafRule::TARGET_USER_AGENT => $request->userAgent,
            WafRule::TARGET_HEADER     => $request->header($rule->target_field ?? '') ?? '',
            WafRule::TARGET_ANY        => implode("\n", array_filter([
                $request->uri,
                $request->query,
                $request->body,
                $request->userAgent,
                $request->header('Referer') ?? '',
                $request->header('Cookie')  ?? '',
            ])),
            default                    => throw new InvalidArgumentException("Unknown WAF target: {$rule->target}"),
        };
    }

    private function logMatch(WafRule $rule, WafRequest $request, string $evidence): void
    {
        Log::channel(config('logging.default'))
            ->info('waf.match', [
                'rule_id'   => $rule->id,
                'rule_slug' => $rule->slug,
                'action'    => $rule->action,
                'evidence'  => $evidence,
                'ip'        => $request->clientIp,
                'method'    => $request->method,
                'uri'       => $request->uri,
            ]);
    }
}
