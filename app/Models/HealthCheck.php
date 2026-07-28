<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class HealthCheck extends Model
{
    use HasFactory;

    public const STATUS_UP       = 'up';
    public const STATUS_DOWN     = 'down';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_TIMEOUT  = 'timeout';

    public const TYPE_HTTP = 'http';
    public const TYPE_TCP  = 'tcp';
    public const TYPE_PING = 'ping';
    public const TYPE_DNS  = 'dns';
    public const TYPE_SSL  = 'ssl';

    protected $fillable = [
        'uuid', 'checkable_type', 'checkable_id', 'check_type', 'target',
        'status', 'response_code', 'latency_ms', 'dns_ms', 'connect_ms',
        'tls_ms', 'first_byte_ms', 'error', 'response_headers',
        'response_body_hash', 'region', 'source_ip', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'response_headers' => 'array',
            'checked_at'       => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (HealthCheck $check) {
            if (empty($check->uuid)) {
                $check->uuid = (string) Str::uuid();
            }
        });
    }

    public function checkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUp(): bool
    {
        return $this->status === self::STATUS_UP;
    }
}
