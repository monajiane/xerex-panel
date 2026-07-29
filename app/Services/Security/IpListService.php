<?php

namespace App\Services\Security;

use App\Models\IpList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Looks up whether a given IP is blocked or allowed.
 *
 * The check order is intentional: allow lists take precedence over block
 * lists so an operator can punch a hole in a CIDR block. If a request
 * matches an allow rule, the IpListCheck middleware lets it through even
 * if a broader block rule also covers it.
 *
 * Lookup is in-memory per request (via $entries) and short-circuits on
 * first match per type to avoid scanning the entire table.
 */
class IpListService
{
    /** @var array<int, IpList>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly int $cacheTtlSeconds = 60,
    ) {}

    /**
     * The decision: true = blocked, false = allowed.
     *
     * Returns the matching IpList (block) for the caller to log, or null
     * when the IP is not in any active block list.
     */
    public function isBlocked(string $ip, ?string $scopeType = null, ?int $scopeId = null): ?IpList
    {
        $ip = $this->normaliseIp($ip);
        if ($ip === null) {
            return null;
        }

        // Allow rules win. If any allow rule covers the IP, the request
        // is permitted regardless of what the block list says.
        if ($this->matchesAllow($ip, $scopeType, $scopeId) !== null) {
            return null;
        }

        return $this->matchesBlock($ip, $scopeType, $scopeId);
    }

    /**
     * Reverse helper: does the IP match an allow rule?
     */
    public function isAllowed(string $ip, ?string $scopeType = null, ?int $scopeId = null): bool
    {
        $ip = $this->normaliseIp($ip);
        if ($ip === null) {
            return false;
        }
        return $this->matchesAllow($ip, $scopeType, $scopeId) !== null;
    }

    /**
     * Invalidate the cached entries — call after add/edit/delete.
     */
    public function flushCache(): void
    {
        $this->cached = null;
        Cache::forget('iplist:entries:active');
    }

    /**
     * Validate a CIDR string and return its normalised form.
     * Returns null when invalid.
     */
    public function normaliseCidr(string $cidr): ?string
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return null;
        }

        // No slash → assume /32 (v4) or /128 (v6).
        if (!str_contains($cidr, '/')) {
            $ip = $this->normaliseIp($cidr);
            if ($ip === null) {
                return null;
            }
            $cidr .= filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? '/32' : '/128';
        }

        [$ip, $bits] = explode('/', $cidr, 2) + [null, null];
        $ip = $this->normaliseIp($ip);
        if ($ip === null || !ctype_digit((string) $bits)) {
            return null;
        }

        $bits = (int) $bits;
        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $max  = $isV4 ? 32 : 128;
        if ($bits < 0 || $bits > $max) {
            return null;
        }
        return $ip . '/' . $bits;
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    private function matchesBlock(string $ip, ?string $type, ?int $id): ?IpList
    {
        foreach ($this->entries() as $entry) {
            if (!$entry->isBlock()) continue;
            if (!$entry->appliesTo($type, $id)) continue;
            if ($this->cidrContains($entry->cidr, $ip)) {
                return $entry;
            }
        }
        return null;
    }

    private function matchesAllow(string $ip, ?string $type, ?int $id): ?IpList
    {
        foreach ($this->entries() as $entry) {
            if (!$entry->isAllow()) continue;
            if (!$entry->appliesTo($type, $id)) continue;
            if ($this->cidrContains($entry->cidr, $ip)) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @return array<int, IpList>
     */
    private function entries(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $cached = Cache::get('iplist:entries:active');
        if (is_array($cached)) {
            $this->cached = IpList::hydrate($cached);
        } else {
            $this->cached = IpList::query()
                ->active()
                ->get()
                ->all();
            Cache::put('iplist:entries:active', array_map(fn ($e) => $e->toArray(), $this->cached), $this->cacheTtlSeconds);
        }
        return $this->cached;
    }

    /**
     * Validate & canonicalise an IP. IPv4 → "a.b.c.d", IPv6 → "::1" form.
     */
    private function normaliseIp(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') return null;

        // Strip zone identifier (fe80::1%eth0) for matching.
        if (str_contains($ip, '%')) {
            $ip = explode('%', $ip, 2)[0];
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $unpacked = @inet_ntop($packed);
        return $unpacked === false ? null : $unpacked;
    }

    /**
     * Check whether a CIDR block contains an IP.
     */
    private function cidrContains(string $cidr, string $ip): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2) + [null, null];
        $bits = (int) $bits;

        $ipBin   = @inet_pton($ip);
        $subBin  = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($subBin)) {
            return false; // mismatched families
        }

        // Build the bit-mask.
        $bytes = strlen($ipBin);
        $fullBytes = intdiv($bits, 8);
        $extraBits = $bits % 8;
        $mask = str_repeat("\xff", $fullBytes)
              . ($extraBits > 0 ? chr((0xff << (8 - $extraBits)) & 0xff) : '')
              . str_repeat("\x00", $bytes - $fullBytes - ($extraBits > 0 ? 1 : 0));

        return ($ipBin & $mask) === ($subBin & $mask);
    }
}
