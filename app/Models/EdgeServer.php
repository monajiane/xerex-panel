<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Edge server model - represents a public-facing proxy node.
 * 
 * @property int    $id
 * @property string $uuid
 * @property string $name
 * @property string $hostname
 * @property string $ip_address
 * @property string $status
 * @property string $agent_token
 */
class EdgeServer extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ONLINE       = 'online';
    public const STATUS_OFFLINE      = 'offline';
    public const STATUS_DEGRADED     = 'degraded';
    public const STATUS_MAINTENANCE  = 'maintenance';

    protected $fillable = [
        'uuid', 'name', 'hostname', 'ip_address', 'ipv6_address',
        'location', 'country_code', 'region', 'datacenter',
        'status', 'agent_version',
        'cpu_usage', 'ram_usage', 'disk_usage',
        'bandwidth_in_bytes', 'bandwidth_out_bytes',
        'active_connections', 'requests_per_second',
        'cpu_cores', 'ram_mb', 'disk_gb', 'bandwidth_mbps',
        'agent_token', 'agent_token_hash', 'agent_token_expires_at',
        'agent_tls_enabled', 'agent_tls_fingerprint',
        'capabilities', 'meta',
        'last_seen_at', 'last_config_at',
    ];

    protected $hidden = [
        'agent_token',
        'agent_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'capabilities'          => 'array',
            'meta'                  => 'array',
            'agent_tls_enabled'     => 'boolean',
            'last_seen_at'          => 'datetime',
            'last_config_at'        => 'datetime',
            'agent_token_expires_at'=> 'datetime',
            'cpu_usage'             => 'decimal:2',
            'ram_usage'             => 'decimal:2',
            'disk_usage'            => 'decimal:2',
            'bandwidth_in_bytes'    => 'integer',
            'bandwidth_out_bytes'   => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EdgeServer $server) {
            if (empty($server->uuid)) {
                $server->uuid = (string) Str::uuid();
            }
            if (empty($server->agent_token)) {
                $server->agent_token = self::generateAgentToken();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'hostname', 'ip_address', 'status', 'location'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ============ Relationships ============

    public function proxyRules(): HasMany
    {
        return $this->hasMany(ProxyRule::class);
    }

    public function dnsRecords(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function trafficLogs(): HasMany
    {
        return $this->hasMany(TrafficLog::class);
    }

    public function healthChecks(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(HealthCheck::class, 'checkable');
    }

    // ============ Helpers ============

    public static function generateAgentToken(): string
    {
        return 'xerx_' . Str::random(48);
    }

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE
            && $this->last_seen_at
            && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }
}
