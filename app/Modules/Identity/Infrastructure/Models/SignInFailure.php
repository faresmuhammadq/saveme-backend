<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $lookup
 * @property int $consecutive_failures
 * @property Carbon|null $locked_until
 * @property Carbon $last_failed_at
 */
class SignInFailure extends Model
{
    protected $primaryKey = 'lookup';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /** Instants keep microseconds, as the syncable envelope promises. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['locked_until' => 'datetime', 'last_failed_at' => 'datetime', 'consecutive_failures' => 'integer'];
    }
}
