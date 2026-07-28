<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZone extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ERROR   = 'error';

    protected $fillable = [
        'zone', 'provider', 'provider_zone_id', 'status', 'error',
        'soa', 'nameservers', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'soa'         => 'array',
            'nameservers' => 'array',
            'is_active'   => 'boolean',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
