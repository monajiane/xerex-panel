<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProxyRule extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    public const TYPE_HTTP       = 'http';
    public const TYPE_WEBSOCKET  = 'websocket';
    public const TYPE_TCP        = 'tcp';
    public const TYPE_GRPC       = 'grpc';
    public const TYPE_SSE        = 'sse';
    public const TYPE_REDIRECT   = 'redirect';

    public const PATH_EXACT   = 'exact';
    public const PATH_PREFIX  = 'prefix';
    public const PATH_REGEX   = 'regex';

    protected $fillable = [
        'uuid', 'domain_id', 'edge_server_id', 'origin_server_id', 'name',
        'type', 'path', 'path_match_type', 'listen_port',
        'force_https', 'http2_enabled', 'http3_enabled',
        'priority', 'weight', 'enabled', 'is_primary',
        'nginx_config', 'config_hash', 'config_generated_at',
        'headers_request', 'headers_response',
        'cache_rules', 'rate_limit', 'access_rules', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'force_https'         => 'boolean',
            'http2_enabled'       => 'boolean',
            'http3_enabled'       => 'boolean',
            'enabled'             => 'boolean',
            'is_primary'          => 'boolean',
            'config_generated_at' => 'datetime',
            'headers_request'     => 'array',
            'headers_response'    => 'array',
            'cache_rules'         => 'array',
            'rate_limit'          => 'array',
            'access_rules'        => 'array',
            'meta'                => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProxyRule $rule) {
            if (empty($rule->uuid)) {
                $rule->uuid = (string) Str::uuid();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'path', 'enabled', 'priority'])
            ->logOnlyDirty();
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function edgeServer(): BelongsTo
    {
        return $this->belongsTo(EdgeServer::class);
    }

    public function originServer(): BelongsTo
    {
        return $this->belongsTo(OriginServer::class);
    }

    public function isWebSocket(): bool
    {
        return $this->type === self::TYPE_WEBSOCKET;
    }

    public function pathMatches(string $requestPath): bool
    {
        return match ($this->path_match_type) {
            self::PATH_EXACT  => $requestPath === $this->path,
            self::PATH_PREFIX => str_starts_with($requestPath, $this->path),
            self::PATH_REGEX  => (bool) preg_match('#' . $this->path . '#', $requestPath),
            default           => false,
        };
    }
}
