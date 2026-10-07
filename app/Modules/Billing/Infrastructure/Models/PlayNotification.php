<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $message_id
 * @property string $kind
 * @property int|null $notification_type
 * @property string|null $product_id
 * @property string|null $token_lookup
 * @property string|null $token_sealed
 * @property CarbonImmutable|null $event_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable $received_at
 * @property string $state
 * @property string|null $ignored_reason
 * @property int $attempts
 * @property CarbonImmutable $updated_at
 */
class PlayNotification extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'notification_type' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
