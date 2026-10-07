<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property string $reference
 * @property string $key_id
 * @property CarbonImmutable $created_at
 */
class PlayAccountReference extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
