<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $session_id
 * @property string $token_sealed
 * @property string $token_lookup
 * @property CarbonImmutable $registered_at
 * @property CarbonImmutable $refreshed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PushRegistration extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** The sealed token and its lookup value never serialise. */
    protected $hidden = ['token_sealed', 'token_lookup'];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
