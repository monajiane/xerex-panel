<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A WAF (Web Application Firewall) rule.
 *
 * Rules are evaluated by the WafEngine in priority order (descending). A
 * rule can be type=regex with a custom pattern, or one of the built-in
 * presets (sql_injection, xss, …) which auto-fills pattern.
 *
 * @property int    $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string $pattern
 * @property string $target
 * @property string|null $target_field
 * @property string $action
 * @property int    $priority
 * @property bool   $is_active
 * @property string|null $scope_type
 * @property int|null $scope_id
 * @property array  $metadata
 */
class WafRule extends Model
{
    use HasFactory;

    public const TYPE_REGEX          = 'regex';
    public const TYPE_SQL_INJECTION  = 'sql_injection';
    public const TYPE_XSS            = 'xss';
    public const TYPE_PATH_TRAVERSAL = 'path_traversal';
    public const TYPE_RCE            = 'rce';
    public const TYPE_USER_AGENT     = 'user_agent';
    public const TYPE_CUSTOM         = 'custom';

    public const TARGET_URI        = 'uri';
    public const TARGET_QUERY      = 'query';
    public const TARGET_BODY       = 'body';
    public const TARGET_HEADER     = 'header';
    public const TARGET_USER_AGENT = 'user_agent';
    public const TARGET_ANY        = 'any';

    public const ACTION_ALLOW      = 'allow';
    public const ACTION_BLOCK      = 'block';
    public const ACTION_CHALLENGE  = 'challenge';
    public const ACTION_LOG        = 'log';
    public const ACTION_RATE_LIMIT = 'rate_limit';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_DOMAIN = 'domain';
    public const SCOPE_EDGE   = 'edge';

    protected $fillable = [
        'uuid', 'name', 'slug', 'description',
        'type', 'pattern',
        'target', 'target_field',
        'action', 'priority', 'is_active',
        'scope_type', 'scope_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'priority'  => 'integer',
            'is_active' => 'boolean',
            'metadata'  => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WafRule $r) {
            if (empty($r->uuid)) {
                $r->uuid = (string) Str::uuid();
            }
            if (empty($r->slug)) {
                $r->slug = Str::slug($r->name);
            }
        });
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForScope($query, ?string $type, ?int $id)
    {
        return $query->where(function ($q) use ($type, $id) {
            $q->where(function ($q2) {
                $q2->whereNull('scope_type')->whereNull('scope_id');
            });
            if ($type && $id) {
                $q->orWhere(function ($q2) use ($type, $id) {
                    $q2->where('scope_type', $type)->where('scope_id', $id);
                });
            }
        });
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    public function appliesTo(?string $type, ?int $id): bool
    {
        if ($this->scope_type === null && $this->scope_id === null) {
            return true; // global
        }
        return $this->scope_type === $type && (int) $this->scope_id === (int) $id;
    }

    public function isBlocking(): bool
    {
        return $this->action === self::ACTION_BLOCK;
    }

    public function isChallenging(): bool
    {
        return $this->action === self::ACTION_CHALLENGE;
    }

    /**
     * Default patterns for the built-in rule types.
     *
     * @return array<string, string>
     */
    public static function presetPatterns(): array
    {
        return [
            self::TYPE_SQL_INJECTION  => "(?i)(?:union\s+select|select\s+.*\s+from|insert\s+into|delete\s+from|drop\s+table|;\s*--|'\s*or\s+'?\d|'=\s*'|0\s*or\s*1=1|benchmark\s*\()",
            self::TYPE_XSS            => "(?i)(?:<\s*script|javascript\s*:|onerror\s*=|onload\s*=|<\s*iframe|<\s*img[^>]+on\w+\s*=|<svg[^>]+on\w+\s*=|alert\s*\(|document\s*\.\s*cookie)",
            self::TYPE_PATH_TRAVERSAL => "(?i)(?:\.\.\/|\.\.\\|%2e%2e%2f|%252e%252e%2f|/etc/passwd|/proc/self|/windows/system32)",
            self::TYPE_RCE            => "(?i)(?:;\s*(?:ls|cat|id|whoami|wget|curl|nc|bash|sh|cmd|powershell)\b|\|\s*(?:ls|cat|id|whoami|wget|curl|nc|bash|sh|cmd|powershell)\b|`[^`]*\$\(|\\$\(.+?\))",
            self::TYPE_USER_AGENT     => "(?i)(?:nikto|sqlmap|nmap|masscan|nuclei|acunetix|burpsuite|wpscan|dirbuster|gobuster|nessus|w3af|skipfish|paros|httperf)",
        ];
    }
}
