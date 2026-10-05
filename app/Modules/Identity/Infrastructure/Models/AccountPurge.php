<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Purge progress for one account.
 *
 * @property string $user_id
 * @property Carbon $started_at
 * @property string $last_step
 * @property int $attempts
 * @property array<string, int> $counts
 * @property Carbon|null $mail_sent_at
 * @property Carbon|null $stall_notified_at
 * @property Carbon $updated_at
 */
class AccountPurge extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'mail_sent_at' => 'immutable_datetime',
            'stall_notified_at' => 'immutable_datetime',
            'counts' => 'array',
        ];
    }
}
