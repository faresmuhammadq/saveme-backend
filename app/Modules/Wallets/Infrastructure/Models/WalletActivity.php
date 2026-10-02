<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One activity entry. Append-only: the repository has no update path.
 *
 * @property string $id
 * @property string $wallet_id
 * @property CarbonImmutable $occurred_at
 * @property string|null $actor_user_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $role
 */
class WalletActivity extends Model
{
    use HasUuids;

    protected $table = 'wallet_activity';

    public $timestamps = false;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
