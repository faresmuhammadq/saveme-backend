<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Billing\Infrastructure\Database\Factories\PlayPurchaseFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $token_lookup
 * @property string|null $token_sealed
 * @property string $kind
 * @property string $product_id
 * @property string|null $user_id
 * @property CarbonImmutable|null $linked_at
 * @property string|null $subscription_id
 * @property string|null $obfuscated_ref
 * @property string|null $replaces_lookup
 * @property string $play_state
 * @property CarbonImmutable|null $acknowledged_at
 * @property bool $is_test
 * @property string|null $base_plan_id
 * @property CarbonImmutable $purchased_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $unreachable_since
 * @property CarbonImmutable|null $ended_by_account_purge_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PlayPurchase extends Model
{
    /** @use HasFactory<PlayPurchaseFactory> */
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
            'linked_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'purchased_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'unreachable_since' => 'immutable_datetime',
            'ended_by_account_purge_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'is_test' => 'boolean',
        ];
    }

    protected static function newFactory(): PlayPurchaseFactory
    {
        return PlayPurchaseFactory::new();
    }
}
