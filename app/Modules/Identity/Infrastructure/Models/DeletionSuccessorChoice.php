<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The owner a departing person chose for one shared wallet.
 *
 * @property string $user_id
 * @property string $wallet_id
 * @property string $member_user_id
 * @property Carbon $chosen_at
 */
class DeletionSuccessorChoice extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['chosen_at' => 'immutable_datetime'];
    }
}
