<?php

namespace App\Modules\Admin\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $operator_id
 * @property string $state
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 */
class OperatorReenrolmentRequest extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }
}
