<?php

namespace App\Modules\Sharing\Infrastructure\Models;

use App\Modules\Sharing\Infrastructure\Database\Factories\InvitationFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $wallet_id
 * @property string $invited_by
 * @property string $role
 * @property string $channel
 * @property string|null $address_sealed
 * @property string|null $address_lookup
 * @property string $token_hash
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property string|null $responder_user_id
 * @property int $version
 * @property int $change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
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
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): InvitationFactory
    {
        return InvitationFactory::new();
    }
}
