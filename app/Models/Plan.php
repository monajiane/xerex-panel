<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A subscription plan / tier.
 *
 * Limits are stored in plan_limits; this model is just the catalog row.
 *
 * @property int    $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string|null $tagline
 * @property string|null $description
 * @property int    $price_cents
 * @property string $currency
 * @property string $billing_period
 * @property bool   $is_active
 * @property bool   $is_public
 * @property bool   $is_default
 * @property int    $trial_days
 * @property int    $sort_order
 * @property array  $features
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'slug', 'name', 'tagline', 'description',
        'price_cents', 'currency', 'billing_period',
        'is_active', 'is_public', 'is_default',
        'trial_days', 'sort_order', 'features', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'trial_days'  => 'integer',
            'sort_order'  => 'integer',
            'is_active'   => 'boolean',
            'is_public'   => 'boolean',
            'is_default'  => 'boolean',
            'features'    => 'array',
            'meta'        => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Plan $plan) {
            if (empty($plan->uuid)) {
                $plan->uuid = (string) Str::uuid();
            }
        });
    }

    /* -----------------------------------------------------------------
     | Relationships
     * ----------------------------------------------------------------- */

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    public function limitFor(string $metric, string $period = 'lifetime'): ?PlanLimit
    {
        return $this->limits()
            ->where('metric', $metric)
            ->where('period', $period)
            ->first();
    }

    public function limitValue(string $metric, string $period = 'lifetime'): ?int
    {
        return $this->limitFor($metric, $period)?->value;
    }

    public function isUnlimited(string $metric, string $period = 'lifetime'): bool
    {
        return ($v = $this->limitValue($metric, $period)) === null || $v === -1;
    }

    /** Format as "$19.00/mo" */
    public function formattedPrice(): string
    {
        $amount = number_format($this->price_cents / 100, 2);
        $sym = $this->currencySymbol();
        $suffix = $this->billing_period === 'year' ? '/yr' : '/mo';
        return $this->price_cents === 0 ? 'Free' : "{$sym}{$amount}{$suffix}";
    }

    protected function currencySymbol(): string
    {
        return match (strtoupper($this->currency)) {
            'USD', 'CAD', 'AUD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'IRR' => '﷼',
            default => $this->currency . ' ',
        };
    }
}
