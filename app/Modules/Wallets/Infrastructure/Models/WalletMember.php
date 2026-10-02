<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use App\Modules\Wallets\Infrastructure\Database\Factories\WalletMemberFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $wallet_id
 * @property string $user_id
 * @property string $role
 * @property int $version
 * @property int $change_seq
 * @property int $member_change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class WalletMember extends Model
{
    /** @use HasFactory<WalletMemberFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'change_seq' => 'integer',
            'member_change_seq' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): WalletMemberFactory
    {
        return WalletMemberFactory::new();
    }
}
