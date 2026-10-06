<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $notification_id
 * @property string $registration_id
 * @property string $state
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PushDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
