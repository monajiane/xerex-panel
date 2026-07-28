<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SslCertificate extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    public const STATUS_PENDING      = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_EXPIRING     = 'expiring';
    public const STATUS_EXPIRED      = 'expired';
    public const STATUS_ERROR        = 'error';

    protected $fillable = [
        'uuid', 'domain_id', 'common_name', 'subject_alt_names',
        'provider', 'status', 'error',
        'cert_path', 'key_path', 'chain_path',
        'issuer', 'serial_number', 'fingerprint_sha256',
        'issued_at', 'expires_at', 'auto_renew',
        'last_renewal_attempt_at', 'renewal_failures',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'subject_alt_names'        => 'array',
            'meta'                     => 'array',
            'issued_at'                => 'datetime',
            'expires_at'               => 'datetime',
            'last_renewal_attempt_at'  => 'datetime',
            'auto_renew'               => 'boolean',
            'renewal_failures'         => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SslCertificate $cert) {
            if (empty($cert->uuid)) {
                $cert->uuid = (string) Str::uuid();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['common_name', 'status', 'expires_at', 'auto_renew'])
            ->logOnlyDirty();
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function expiresSoon(int $days = 14): bool
    {
        return $this->expires_at && $this->expires_at->lt(now()->addDays($days));
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
