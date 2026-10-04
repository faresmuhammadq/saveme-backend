<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Billing\Infrastructure\Database\Factories\RedeemCodeFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $claim_id
 * @property string $code_hash
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property string|null $redeemed_by
 * @property CarbonImmutable|null $redeemed_at
 * @property string|null $subscription_id
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class RedeemCode extends Model
{
    /** @use HasFactory<RedeemCodeFactory> */
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
            'expires_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): RedeemCodeFactory
    {
        return RedeemCodeFactory::new();
    }
}
