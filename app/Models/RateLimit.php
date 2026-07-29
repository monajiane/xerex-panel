<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A rate-limit policy.
 *
 * "For requests matching scope/scope_id, allow at most max_requests
 * within window_seconds; bucket the counter by limit_type (ip, user, …);
 * on overflow take `action`."
 *
 * @property int    $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $scope_type
 * @property int|null $scope_id
 * @property string $limit_type
 * @property int    $max_requests
 * @property int    $window_seconds
 * @property float  $burst_multiplier
 * @property string $action
 * @property bool   $is_active
 * @property array  $metadata
 */
class RateLimit extends Model
{
    use HasFactory;

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_DOMAIN = 'domain';
    public const SCOPE_EDGE   = 'edge';
    public const SCOPE_USER   = 'user';

    public const LIMIT_IP     = 'ip';
    public const LIMIT_USER   = 'user';
    public const LIMIT_PATH   = 'path';
    public const LIMIT_DOMAIN = 'domain';

    public const ACTION_BLOCK     = 'block';
    public const ACTION_CHALLENGE = 'challenge';
    public const ACTION_THROTTLE  = 'throttle';
    public const ACTION_LOG       = 'log';

    protected $fillable = [
        'uuid', 'name', 'slug', 'description',
        'scope_type', 'scope_id', 'limit_type',
        'max_requests', 'window_seconds', 'burst_multiplier',
        'action', 'is_active', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'max_requests'     => 'integer',
            'window_seconds'   => 'integer',
            'burst_multiplier' => 'float',
            'is_active'        => 'boolean',
            'metadata'         => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RateLimit $r) {
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

    public function scopeForScope($query, string $type, ?int $id)
    {
        return $query->where('scope_type', $type)
            ->where(function ($q) use ($id) {
                $id === null
                    ? $q->whereNull('scope_id')
                    : $q->where('scope_id', $id);
            });
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    public function effectiveMax(): int
    {
        return (int) ceil($this->max_requests * max(1.0, (float) $this->burst_multiplier));
    }

    public function isBlocking(): bool
    {
        return in_array($this->action, [self::ACTION_BLOCK, self::ACTION_CHALLENGE], true);
    }
}
