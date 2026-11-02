<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A shared budget row: see the migration. The id is the client's UUIDv7.
 *
 * @property string $id
 * @property string $owner_id
 * @property string $name
 * @property string $currency
 * @property int $start_day
 * @property string $timezone
 * @property int|null $limit_minor
 * @property CarbonImmutable|null $archived_at
 * @property int $version
 * @property int $change_seq
 * @property int $purged_through
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class SharedBudget extends Model
{
    public $incrementing = false;

    protected $table = 'shared_budgets';

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
            'limit_minor' => 'integer',
            'version' => 'integer',
            'change_seq' => 'integer',
            'purged_through' => 'integer',
            'archived_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
