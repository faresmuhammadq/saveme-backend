<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A person's data key, wrapped by the keyring key `kid` (spec 007, R-6).
 *
 * @property string $user_id
 * @property string $wrapped_key
 * @property string $kid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UserKey extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds, as the syncable envelope promises. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
