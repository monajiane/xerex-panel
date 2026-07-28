<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id', 'edge_server_id', 'proxy_rule_id',
        'method', 'scheme', 'url', 'host', 'path',
        'response_code', 'bytes_sent', 'bytes_received',
        'request_time_ms', 'upstream_time_ms',
        'client_ip', 'user_agent', 'referer',
        'protocol', 'cached', 'cache_status',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'cached'            => 'boolean',
            'bytes_sent'        => 'integer',
            'bytes_received'    => 'integer',
            'request_time_ms'   => 'integer',
            'upstream_time_ms'  => 'integer',
            'response_code'     => 'integer',
            'logged_at'         => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function edgeServer(): BelongsTo
    {
        return $this->belongsTo(EdgeServer::class);
    }

    public function proxyRule(): BelongsTo
    {
        return $this->belongsTo(ProxyRule::class);
    }
}
