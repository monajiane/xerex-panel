<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-aggregated hourly traffic counters. See TrafficAggregator for how
 * the rows are produced.
 */
class TrafficRollup extends Model
{
    use HasFactory;

    protected $table = 'traffic_rollups';

    protected $fillable = [
        'edge_server_id', 'domain_id', 'proxy_rule_id', 'bucket',
        'requests', 'bytes_in', 'bytes_out',
        'cache_hits', 'cache_misses',
        'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx',
        'request_time_sum_ms', 'upstream_time_sum_ms',
        'unique_clients',
    ];

    protected function casts(): array
    {
        return [
            'bucket'              => 'datetime',
            'requests'            => 'integer',
            'bytes_in'            => 'integer',
            'bytes_out'           => 'integer',
            'cache_hits'          => 'integer',
            'cache_misses'        => 'integer',
            'status_2xx'          => 'integer',
            'status_3xx'          => 'integer',
            'status_4xx'          => 'integer',
            'status_5xx'          => 'integer',
            'request_time_sum_ms' => 'integer',
            'upstream_time_sum_ms'=> 'integer',
            'unique_clients'      => 'integer',
        ];
    }

    public function edgeServer(): BelongsTo
    {
        return $this->belongsTo(EdgeServer::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function proxyRule(): BelongsTo
    {
        return $this->belongsTo(ProxyRule::class);
    }
}
