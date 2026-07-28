<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Domain extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    public const SSL_PENDING       = 'pending';
    public const SSL_PROVISIONING  = 'provisioning';
    public const SSL_ACTIVE        = 'active';
    public const SSL_EXPIRING      = 'expiring';
    public const SSL_EXPIRED       = 'expired';
    public const SSL_ERROR         = 'error';

    protected $fillable = [
        'uuid', 'user_id', 'domain', 'registrar', 'expires_at',
        'dns_status', 'dns_verified_at', 'dns_provider',
        'ssl_status', 'ssl_provider', 'ssl_issued_at', 'ssl_expires_at',
        'ssl_fingerprint', 'wildcard', 'auto_renew',
        'is_active', 'cdn_enabled', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'        => 'date',
            'dns_verified_at'   => 'datetime',
            'ssl_issued_at'     => 'datetime',
            'ssl_expires_at'    => 'datetime',
            'wildcard'          => 'boolean',
            'auto_renew'        => 'boolean',
            'is_active'         => 'boolean',
            'cdn_enabled'       => 'boolean',
            'meta'              => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Domain $domain) {
            if (empty($domain->uuid)) {
                $domain->uuid = (string) Str::uuid();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['domain', 'dns_status', 'ssl_status', 'is_active', 'cdn_enabled'])
            ->logOnlyDirty();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proxyRules(): HasMany
    {
        return $this->hasMany(ProxyRule::class);
    }

    public function dnsRecords(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function sslCertificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function activeCertificate(): HasOne
    {
        return $this->hasOne(SslCertificate::class)->where('status', SslCertificate::STATUS_ACTIVE)->latestOfMany();
    }

    public function trafficLogs(): HasMany
    {
        return $this->hasMany(TrafficLog::class);
    }

    public function isSslActive(): bool
    {
        return $this->ssl_status === self::SSL_ACTIVE;
    }

    public function sslExpiresSoon(int $days = 14): bool
    {
        return $this->ssl_expires_at && $this->ssl_expires_at->lt(now()->addDays($days));
    }
}
