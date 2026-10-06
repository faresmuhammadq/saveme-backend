<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $budget_id
 * @property string $user_id
 * @property string $period_start
 * @property int $threshold
 * @property CarbonImmutable $crossed_at
 */
class BudgetOverrun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'crossed_at' => 'immutable_datetime',
        ];
    }
}
