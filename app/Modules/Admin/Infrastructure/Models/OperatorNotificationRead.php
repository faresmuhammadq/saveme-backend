<?php

namespace App\Modules\Admin\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $operator_id
 * @property string $notification_id
 * @property Carbon $read_at
 */
class OperatorNotificationRead extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
