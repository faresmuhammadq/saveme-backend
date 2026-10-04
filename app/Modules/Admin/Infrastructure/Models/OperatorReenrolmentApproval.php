<?php

namespace App\Modules\Admin\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property string $approver_id
 * @property CarbonImmutable $approved_at
 */
class OperatorReenrolmentApproval extends Model
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
        return ['approved_at' => 'immutable_datetime'];
    }
}
