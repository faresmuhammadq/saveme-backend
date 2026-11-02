<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A shared budget line row: see the migration. The id is the client's UUIDv7.
 *
 * @property string $id
 * @property string $shared_budget_id
 * @property string $category_id
 * @property int|null $limit_minor
 * @property string|null $name
 * @property string|null $icon
 * @property string|null $color
 * @property int $version
 * @property int $change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class SharedBudgetLine extends Model
{
    public $incrementing = false;

    protected $table = 'shared_budget_lines';

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
            'version' => 'integer',
            'change_seq' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
