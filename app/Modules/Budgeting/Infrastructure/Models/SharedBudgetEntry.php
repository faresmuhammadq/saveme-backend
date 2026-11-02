<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A shared budget entry row: see the migration. It never holds a wallet.
 *
 * @property string $id
 * @property string $shared_budget_id
 * @property string $transaction_id
 * @property string $created_by
 * @property string $category_id
 * @property string $date
 * @property int $amount_minor
 * @property string|null $description
 * @property CarbonImmutable|null $detached_at
 * @property int $version
 * @property int $change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class SharedBudgetEntry extends Model
{
    public $incrementing = false;

    protected $table = 'shared_budget_entries';

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
            'amount_minor' => 'integer',
            'version' => 'integer',
            'change_seq' => 'integer',
            'detached_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
