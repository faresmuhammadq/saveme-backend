<?php

namespace App\Modules\Admin\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $operator_id
 * @property string $set_id
 * @property string $code_hash
 * @property CarbonImmutable|null $used_at
 * @property Carbon $created_at
 */
class OperatorRecoveryCode extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime'];
    }
}
