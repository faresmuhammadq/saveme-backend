<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Billing\Infrastructure\Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $code
 * @property array{ar: string, en: string} $name
 * @property string $period_type
 * @property int|null $period_days
 * @property bool $is_default
 * @property string $status
 * @property int $sort_order
 * @property array<string, bool|int|null> $features
 * @property list<array{channel: string, product_id: ?string, amount_minor: int, currency: string}> $prices
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'features' => 'array',
            'prices' => 'array',
            'is_default' => 'boolean',
            'period_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
