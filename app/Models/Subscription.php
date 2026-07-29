<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int    $id
 * @property string $uuid
 * @property int    $user_id
 * @property int    $plan_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property bool   $cancel_at_period_end
 */
class Subscription extends Model
{
    use HasFactory;

    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED  = 'expired';

    protected $fillable = [
        'uuid', 'user_id', 'plan_id', 'status',
        'trial_ends_at', 'starts_at',
        'current_period_start', 'current_period_end',
        'cancel_at_period_end', 'canceled_at', 'ended_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at'         => 'datetime',
            'starts_at'             => 'datetime',
            'current_period_start'  => 'datetime',
            'current_period_end'    => 'datetime',
            'canceled_at'           => 'datetime',
            'ended_at'              => 'datetime',
            'cancel_at_period_end'  => 'boolean',
            'meta'                  => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscription $s) {
            if (empty($s->uuid)) {
                $s->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING], true);
    }

    public function isTrialing(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture();
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) return true;
        return $this->current_period_end && $this->current_period_end->isPast();
    }
}
