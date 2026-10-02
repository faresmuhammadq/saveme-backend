<?php

namespace App\Modules\Budgeting\Infrastructure\Models;

use App\Modules\Budgeting\Infrastructure\Database\Factories\BudgetFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A budget row: see the migration.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $category_id
 * @property string $period_type
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable|null $archived_at
 * @property int $version
 * @property int $change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    /** The id is the client's UUIDv7, never generated here. */
    public $incrementing = false;

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
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): BudgetFactory
    {
        return BudgetFactory::new();
    }
}
