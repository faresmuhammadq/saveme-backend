<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Database\Factories\ProfileFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $locale
 * @property string $timezone
 * @property string $default_currency
 * @property int $budget_period_start_day
 * @property CarbonImmutable|null $budget_period_start_day_from
 * @property string|null $display_name_ciphertext
 * @property int $version
 * @property int $change_seq
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** Instants keep microseconds, as the syncable envelope promises. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'change_seq' => 'integer',
            'budget_period_start_day' => 'integer',
            'budget_period_start_day_from' => 'immutable_date',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ProfileFactory
    {
        return ProfileFactory::new();
    }
}
