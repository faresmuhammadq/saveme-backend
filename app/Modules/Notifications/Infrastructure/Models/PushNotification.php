<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $type
 * @property string $category
 * @property string $dedupe_key
 * @property array<string, string> $data
 * @property string $collapse_key
 * @property string $state
 * @property string|null $skip_reason
 * @property CarbonImmutable|null $not_before
 * @property CarbonImmutable|null $sending_since
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PushNotification extends Model
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
            'data' => 'array',
            'not_before' => 'immutable_datetime',
            'sending_since' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
