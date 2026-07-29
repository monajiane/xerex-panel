<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int    $id
 * @property string $uuid
 * @property int    $user_id
 * @property int|null $subscription_id
 * @property string $number
 * @property string $status        // draft|open|paid|void|uncollectible
 * @property string $currency
 * @property int    $subtotal_cents
 * @property int    $tax_cents
 * @property int    $total_cents
 * @property array  $line_items
 */
class Invoice extends Model
{
    use HasFactory;

    public const STATUS_DRAFT         = 'draft';
    public const STATUS_OPEN          = 'open';
    public const STATUS_PAID          = 'paid';
    public const STATUS_VOID          = 'void';
    public const STATUS_UNCOLLECTIBLE = 'uncollectible';

    protected $fillable = [
        'uuid', 'user_id', 'subscription_id', 'number', 'status',
        'currency', 'subtotal_cents', 'tax_cents', 'total_cents',
        'line_items', 'period_start', 'period_end',
        'issued_at', 'due_at', 'paid_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'tax_cents'      => 'integer',
            'total_cents'    => 'integer',
            'line_items'     => 'array',
            'period_start'   => 'datetime',
            'period_end'     => 'datetime',
            'issued_at'      => 'datetime',
            'due_at'         => 'datetime',
            'paid_at'        => 'datetime',
            'meta'           => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $i) {
            if (empty($i->uuid)) {
                $i->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPaid(): bool { return $this->status === self::STATUS_PAID; }
    public function isOpen(): bool { return $this->status === self::STATUS_OPEN; }
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at && $this->due_at->isPast();
    }

    public function formattedTotal(): string
    {
        $amount = number_format($this->total_cents / 100, 2);
        return match (strtoupper($this->currency)) {
            'USD', 'CAD', 'AUD' => "\${$amount}",
            'EUR' => "€{$amount}",
            'GBP' => "£{$amount}",
            default => "{$this->currency} {$amount}",
        };
    }
}
