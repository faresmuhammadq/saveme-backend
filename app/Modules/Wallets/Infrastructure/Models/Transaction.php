<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Domain\Transactions\TransactionRecord;
use App\Modules\Wallets\Infrastructure\Database\Factories\TransactionFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $wallet_id
 * @property string $kind
 * @property string $date
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $category_id
 * @property string|null $counter_wallet_id
 * @property string|null $description
 * @property string|null $memo
 * @property string|null $counterparty
 * @property bool $cleared
 * @property string $created_by
 * @property int $version
 * @property int $change_seq
 * @property int|null $counter_change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /** The id is the client's UUIDv7, not generated here. */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * `date` is a calendar date and stays the plain `YYYY-MM-DD` string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'cleared' => 'boolean',
            'version' => 'integer',
            'change_seq' => 'integer',
            'counter_change_seq' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): TransactionFactory
    {
        return TransactionFactory::new();
    }

    public function toRecord(): TransactionRecord
    {
        return new TransactionRecord(
            id: $this->id,
            walletId: $this->wallet_id,
            kind: TransactionKind::from($this->kind),
            date: $this->date,
            amountMinor: $this->amount_minor,
            currency: $this->currency,
            categoryId: $this->category_id,
            counterWalletId: $this->counter_wallet_id,
            description: $this->description,
            memo: $this->memo,
            counterparty: $this->counterparty,
            cleared: $this->cleared,
            createdBy: $this->created_by,
            version: $this->version,
            changeSeq: $this->change_seq,
            counterChangeSeq: $this->counter_change_seq,
            createdAt: $this->created_at->toDateTimeImmutable(),
            updatedAt: $this->updated_at->toDateTimeImmutable(),
            deletedAt: $this->deleted_at?->toDateTimeImmutable(),
        );
    }
}
