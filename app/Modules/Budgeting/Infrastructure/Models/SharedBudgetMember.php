<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A shared budget membership row: see the migration.
 *
 * @property string $id
 * @property string $shared_budget_id
 * @property string $user_id
 * @property string $role
 * @property bool $read_only
 * @property int $version
 * @property int $change_seq
 * @property int $member_change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class SharedBudgetMember extends Model
{
    public $incrementing = false;

    protected $table = 'shared_budget_members';

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
            'read_only' => 'boolean',
            'version' => 'integer',
            'change_seq' => 'integer',
            'member_change_seq' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
