<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use App\Modules\Wallets\Infrastructure\Database\Factories\WalletFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $type
 * @property string $name
 * @property string $currency
 * @property int $opening_balance_minor
 * @property int $balance_minor
 * @property string|null $icon
 * @property string|null $color
 * @property CarbonImmutable|null $archived_at
 * @property int $version
 * @property int $change_seq
 * @property int $purged_change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /** The id is the client's UUIDv7, not generated here. */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance_minor' => 'integer',
            'balance_minor' => 'integer',
            'version' => 'integer',
            'change_seq' => 'integer',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): WalletFactory
    {
        return WalletFactory::new();
    }
}
