<?php

namespace App\Modules\Admin\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kind
 * @property string $subject_user_id
 * @property Carbon $occurred_at
 * @property array<string, scalar|null> $payload
 * @property string $source_event_id
 * @property Carbon $created_at
 */
class OperatorNotification extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'payload' => 'array'];
    }
}
