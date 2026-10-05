<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Billing\Infrastructure\Database\Factories\SubscriptionFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $plan_id
 * @property string $provider
 * @property string $provider_ref
 * @property string $status
 * @property CarbonImmutable $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $grace_until
 * @property bool $auto_renew
 * @property array<string, scalar|null> $source_meta
 * @property CarbonImmutable|null $renewal_reminded_at
 * @property CarbonImmutable|null $ended_by_account_purge_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
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
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'grace_until' => 'immutable_datetime',
            'renewal_reminded_at' => 'immutable_datetime',
            'ended_by_account_purge_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'auto_renew' => 'boolean',
            'source_meta' => 'array',
        ];
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
