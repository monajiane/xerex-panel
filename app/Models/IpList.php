<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An IP allow- or block-list entry.
 *
 * @property int    $id
 * @property string $uuid
 * @property string $cidr
 * @property string $list_type
 * @property string|null $reason
 * @property string|null $source
 * @property string|null $scope_type
 * @property int|null $scope_id
 * @property Carbon|null $expires_at
 * @property int|null $created_by
 */
class IpList extends Model
{
    use HasFactory;

    public const TYPE_ALLOW = 'allow';
    public const TYPE_BLOCK = 'block';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_DOMAIN = 'domain';
    public const SCOPE_EDGE   = 'edge';

    protected $fillable = [
        'uuid', 'cidr', 'list_type', 'reason', 'source',
        'scope_type', 'scope_id', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (IpList $entry) {
            if (empty($entry->uuid)) {
                $entry->uuid = (string) Str::uuid();
            }
        });
    }

    /* -----------------------------------------------------------------
     | Relationships
     * ----------------------------------------------------------------- */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scope(): ?Model
    {
        if (!$this->scope_type || !$this->scope_id) {
            return null;
        }
        return match ($this->scope_type) {
            self::SCOPE_DOMAIN => Domain::find($this->scope_id),
            self::SCOPE_EDGE   => EdgeServer::find($this->scope_id),
            default            => null,
        };
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopeAllow($query)
    {
        return $query->where('list_type', self::TYPE_ALLOW);
    }

    public function scopeBlock($query)
    {
        return $query->where('list_type', self::TYPE_BLOCK);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
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

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isAllow(): bool
    {
        return $this->list_type === self::TYPE_ALLOW;
    }

    public function isBlock(): bool
    {
        return $this->list_type === self::TYPE_BLOCK;
    }
}
