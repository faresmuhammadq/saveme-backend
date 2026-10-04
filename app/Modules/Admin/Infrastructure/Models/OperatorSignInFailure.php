<?php

namespace App\Modules\Admin\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $lookup
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable $last_failed_at
 */
class OperatorSignInFailure extends Model
{
    protected $primaryKey = 'lookup';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['locked_until' => 'immutable_datetime', 'last_failed_at' => 'immutable_datetime'];
    }
}
