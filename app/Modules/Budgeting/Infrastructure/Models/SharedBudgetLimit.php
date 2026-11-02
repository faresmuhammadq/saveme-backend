<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One dated limit of a budget or line: see the migration.
 *
 * @property string $id
 * @property string $shared_budget_id
 * @property string|null $line_id
 * @property int|null $limit_minor
 * @property string $applies_from
 * @property int $change_seq
 */
class SharedBudgetLimit extends Model
{
    public $incrementing = false;

    protected $table = 'shared_budget_limits';

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
            'limit_minor' => 'integer',
            'change_seq' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
