<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Origin server model - the backend that edges proxy to.
 *
 * @property int    $id
 * @property string $host
 * @property int    $port
 * @property string $protocol
 * @property string $health_status
 */
class OriginServer extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    public const PROTOCOL_HTTP  = 'http';
    public const PROTOCOL_HTTPS = 'https';
    public const PROTOCOL_GRPC  = 'grpc';
    public const PROTOCOL_TCP   = 'tcp';

    public const HEALTH_UP      = 'up';
    public const HEALTH_DOWN    = 'down';
    public const HEALTH_UNKNOWN = 'unknown';

    protected $fillable = [
        'uuid', 'user_id', 'name', 'host', 'port', 'protocol', 'upstream_type',
        'ssl_enabled', 'ssl_verify', 'ssl_sni',
        'weight', 'max_fails', 'fail_timeout',
        'health_check_enabled', 'health_check_path', 'health_check_interval',
        'health_check_timeout', 'health_check_expected_status', 'health_check_use_tls',
        'health_status', 'last_health_check_at', 'consecutive_failures',
        'max_connections', 'connect_timeout', 'read_timeout', 'send_timeout',
        'headers', 'meta', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'ssl_enabled'           => 'boolean',
            'ssl_verify'            => 'boolean',
            'health_check_enabled'  => 'boolean',
            'health_check_use_tls'  => 'boolean',
            'is_active'             => 'boolean',
            'headers'               => 'array',
            'meta'                  => 'array',
            'last_health_check_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OriginServer $origin) {
            if (empty($origin->uuid)) {
                $origin->uuid = (string) Str::uuid();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'host', 'port', 'protocol', 'is_active', 'health_status'])
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

    public function healthChecks(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(HealthCheck::class, 'checkable');
    }

    public function getUpstreamUrl(): string
    {
        $scheme = $this->ssl_enabled ? 'https' : ($this->protocol === 'https' ? 'https' : 'http');
        return sprintf('%s://%s:%d', $scheme, $this->host, $this->port);
    }

    public function isHealthy(): bool
    {
        return $this->health_status === self::HEALTH_UP;
    }

    public function markHealthCheck(bool $success): void
    {
        $this->health_status = $success ? self::HEALTH_UP : self::HEALTH_DOWN;
        $this->consecutive_failures = $success ? 0 : $this->consecutive_failures + 1;
        $this->last_health_check_at = now();
        $this->save();
    }
}
