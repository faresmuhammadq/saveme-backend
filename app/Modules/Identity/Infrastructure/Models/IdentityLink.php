<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $kind
 * @property string $secret_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $superseded_at
 * @property Carbon $created_at
 */
class IdentityLink extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** Instants keep microseconds, as the syncable envelope promises. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'superseded_at' => 'datetime'];
    }
}
