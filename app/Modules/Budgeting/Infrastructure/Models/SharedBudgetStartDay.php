<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One dated start day of a budget: see the migration.
 *
 * @property string $id
 * @property string $shared_budget_id
 * @property int $start_day
 * @property string|null $applies_from
 * @property int $change_seq
 */
class SharedBudgetStartDay extends Model
{
    public $incrementing = false;

    protected $table = 'shared_budget_start_days';

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_day' => 'integer',
            'change_seq' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
