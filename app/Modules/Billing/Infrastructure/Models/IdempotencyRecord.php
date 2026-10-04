<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $scope
 * @property string $key
 * @property string $fingerprint
 * @property string $state
 * @property int|null $response_status
 * @property string|null $response_body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $expires_at
 */
class IdempotencyRecord extends Model
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
            'response_status' => 'integer',
            'created_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
