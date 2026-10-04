<?php

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Billing\Infrastructure\Database\Factories\PaymentClaimFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $plan_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $reference
 * @property string|null $address_sealed
 * @property string|null $address_lookup
 * @property string $locale
 * @property string|null $receipt_path
 * @property string|null $receipt_media_type
 * @property int|null $receipt_bytes
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $reject_reason
 * @property string|null $review_note
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $erased_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PaymentClaim extends Model
{
    /** @use HasFactory<PaymentClaimFactory> */
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
            'amount_minor' => 'integer',
            'receipt_bytes' => 'integer',
            'expires_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'erased_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): PaymentClaimFactory
    {
        return PaymentClaimFactory::new();
    }
}
