<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One usage row per (user, metric, period_start).
 * `quantity` is monotonically incremented inside the period.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $metric
 * @property int    $quantity
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property \Illuminate\Support\Carbon|null $last_incremented_at
 */
class Usage extends Model
{
    use HasFactory;

    protected $table = 'usages';

    protected $fillable = [
        'user_id', 'metric', 'quantity',
        'period_start', 'period_end',
        'last_incremented_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity'            => 'integer',
            'period_start'        => 'datetime',
            'period_end'          => 'datetime',
            'last_incremented_at' => 'datetime',
            'meta'                => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
