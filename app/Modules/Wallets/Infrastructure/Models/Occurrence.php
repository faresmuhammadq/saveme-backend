<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use App\Modules\Wallets\Domain\Recurring\AutoConfirmRefusal;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\OccurrenceStatus;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Infrastructure\Database\Factories\OccurrenceFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $wallet_id
 * @property string $recurring_rule_id
 * @property int $ordinal
 * @property bool $is_payout
 * @property string $scheduled_date
 * @property string $date
 * @property string $status
 * @property string $kind
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $category_id
 * @property string|null $counter_wallet_id
 * @property string|null $description
 * @property string|null $memo
 * @property string|null $counterparty
 * @property bool $edited
 * @property string|null $transaction_id
 * @property string|null $auto_confirm_refusal
 * @property int $version
 * @property int $change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Occurrence extends Model
{
    /** @use HasFactory<OccurrenceFactory> */
    use HasFactory;

    /** The id is the server's UUIDv7, minted when it materialises the occurrence. */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * The date columns stay plain `YYYY-MM-DD` strings.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'is_payout' => 'boolean',
            'amount_minor' => 'integer',
            'edited' => 'boolean',
            'version' => 'integer',
            'change_seq' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): OccurrenceFactory
    {
        return OccurrenceFactory::new();
    }

    public function toRecord(): OccurrenceRecord
    {
        return new OccurrenceRecord(
            id: $this->id,
            walletId: $this->wallet_id,
            recurringRuleId: $this->recurring_rule_id,
            ordinal: $this->ordinal,
            isPayout: $this->is_payout,
            scheduledDate: $this->scheduled_date,
            date: $this->date,
            status: OccurrenceStatus::from($this->status),
            kind: TransactionKind::from($this->kind),
            amountMinor: $this->amount_minor,
            currency: $this->currency,
            categoryId: $this->category_id,
            counterWalletId: $this->counter_wallet_id,
            description: $this->description,
            memo: $this->memo,
            counterparty: $this->counterparty,
            edited: $this->edited,
            transactionId: $this->transaction_id,
            autoConfirmRefusal: $this->auto_confirm_refusal === null ? null : AutoConfirmRefusal::from($this->auto_confirm_refusal),
            version: $this->version,
            changeSeq: $this->change_seq,
            createdAt: $this->created_at->toDateTimeImmutable(),
            updatedAt: $this->updated_at->toDateTimeImmutable(),
            deletedAt: $this->deleted_at?->toDateTimeImmutable(),
        );
    }
}
