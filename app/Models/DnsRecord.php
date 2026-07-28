<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DnsRecord extends Model
{
    use HasFactory;

    public const TYPE_A     = 'A';
    public const TYPE_AAAA  = 'AAAA';
    public const TYPE_CNAME = 'CNAME';
    public const TYPE_TXT   = 'TXT';
    public const TYPE_MX    = 'MX';
    public const TYPE_NS    = 'NS';
    public const TYPE_SRV   = 'SRV';
    public const TYPE_CAA   = 'CAA';

    protected $fillable = [
        'uuid', 'dns_zone_id', 'domain_id', 'edge_server_id',
        'name', 'type', 'value', 'ttl', 'priority', 'meta',
        'provider_record_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'meta'      => 'array',
            'is_active' => 'boolean',
            'ttl'       => 'integer',
            'priority'  => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DnsRecord $record) {
            if (empty($record->uuid)) {
                $record->uuid = (string) Str::uuid();
            }
        });
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function edgeServer(): BelongsTo
    {
        return $this->belongsTo(EdgeServer::class);
    }

    public function getFqdn(): string
    {
        $name = $this->name === '@' ? '' : ($this->name . '.');
        return $name . $this->zone?->zone;
    }
}
