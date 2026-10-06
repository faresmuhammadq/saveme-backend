<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property bool $recurring_due
 * @property bool $budget_exceeded
 * @property bool $sharing
 * @property bool $billing
 * @property bool $export_ready
 * @property bool $deletion_reminder
 * @property bool $quiet_hours_enabled
 * @property int $quiet_hours_start
 * @property int $quiet_hours_end
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class NotificationPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recurring_due' => 'boolean',
            'budget_exceeded' => 'boolean',
            'sharing' => 'boolean',
            'billing' => 'boolean',
            'export_ready' => 'boolean',
            'deletion_reminder' => 'boolean',
            'quiet_hours_enabled' => 'boolean',
            'quiet_hours_start' => 'integer',
            'quiet_hours_end' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
