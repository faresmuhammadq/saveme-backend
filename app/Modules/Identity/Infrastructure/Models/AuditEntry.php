<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One recorded security or privacy event. Append-only: the database refuses
 * edits and early deletes (security baseline, The audit entry).
 *
 * @property string $id
 * @property Carbon $occurred_at
 * @property string $event
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $subject_type
 * @property string|null $subject_id
 * @property string|null $request_id
 * @property string|null $origin
 * @property array<string, scalar|null> $detail
 */
class AuditEntry extends Model
{
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
        return ['occurred_at' => 'datetime', 'detail' => 'array'];
    }
}
