<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-metric numeric limit for a plan. value = -1 means unlimited.
 *
 * @property int    $id
 * @property int    $plan_id
 * @property string $metric
 * @property int    $value
 * @property string $period     // lifetime | month | day | hour
 * @property bool   $is_soft    // warn but don't block
 */
class PlanLimit extends Model
{
    use HasFactory;

    public const UNLIMITED = -1;

    protected $fillable = [
        'plan_id', 'metric', 'value', 'period', 'is_soft',
    ];

    protected function casts(): array
    {
        return [
            'value'   => 'integer',
            'is_soft' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isUnlimited(): bool
    {
        return $this->value === self::UNLIMITED;
    }
}
