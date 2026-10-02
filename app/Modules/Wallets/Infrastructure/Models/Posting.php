<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The ledger leg (ADR-0012): never exposed, never synced, so it has no
 * timestamps. Written only through the posting repository.
 *
 * @property string $id
 * @property string $transaction_id
 * @property string|null $wallet_id
 * @property string|null $category_id
 * @property int $amount_minor
 * @property string $currency
 */
class Posting extends Model
{
    /** The server's UUIDv7, assigned by the repository. */
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
