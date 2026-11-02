<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use App\Modules\Wallets\Domain\Recurring\AmountBasis;
use App\Modules\Wallets\Domain\Recurring\Frequency;
use App\Modules\Wallets\Domain\Recurring\Preset;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Infrastructure\Database\Factories\RecurringRuleFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $wallet_id
 * @property string $preset
 * @property string $status
 * @property string $kind
 * @property int $amount_minor
 * @property string $amount_basis
 * @property string $currency
 * @property string|null $category_id
 * @property string|null $counter_wallet_id
 * @property string|null $description
 * @property string|null $memo
 * @property string|null $counterparty
 * @property bool $auto_confirm
 * @property string $auto_confirm_from
 * @property string $frequency
 * @property int $interval
 * @property int|null $anchor_day
 * @property string $start_date
 * @property string|null $end_date
 * @property int|null $occurrence_count
 * @property int|null $payout_round
 * @property string|null $payout_category_id
 * @property string|null $shared_budget_id
 * @property string $timezone
 * @property string $created_by
 * @property int $version
 * @property int $change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class RecurringRule extends Model
{
    /** @use HasFactory<RecurringRuleFactory> */
    use HasFactory;

    /** The id is the client's UUIDv7, not generated here. */
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
            'amount_minor' => 'integer',
            'auto_confirm' => 'boolean',
            'interval' => 'integer',
            'anchor_day' => 'integer',
            'occurrence_count' => 'integer',
            'payout_round' => 'integer',
            'version' => 'integer',
            'change_seq' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): RecurringRuleFactory
    {
        return RecurringRuleFactory::new();
    }

    public function toRecord(): RecurringRuleRecord
    {
        return new RecurringRuleRecord(
            id: $this->id,
            walletId: $this->wallet_id,
            preset: Preset::from($this->preset),
            status: RuleStatus::from($this->status),
            kind: TransactionKind::from($this->kind),
            amountMinor: $this->amount_minor,
            amountBasis: AmountBasis::from($this->amount_basis),
            currency: $this->currency,
            categoryId: $this->category_id,
            counterWalletId: $this->counter_wallet_id,
            description: $this->description,
            memo: $this->memo,
            counterparty: $this->counterparty,
            autoConfirm: $this->auto_confirm,
            autoConfirmFrom: $this->auto_confirm_from,
            frequency: Frequency::from($this->frequency),
            interval: $this->interval,
            anchorDay: $this->anchor_day,
            startDate: $this->start_date,
            endDate: $this->end_date,
            occurrenceCount: $this->occurrence_count,
            payoutRound: $this->payout_round,
            payoutCategoryId: $this->payout_category_id,
            timezone: $this->timezone,
            createdBy: $this->created_by,
            version: $this->version,
            changeSeq: $this->change_seq,
            createdAt: $this->created_at->toDateTimeImmutable(),
            updatedAt: $this->updated_at->toDateTimeImmutable(),
            deletedAt: $this->deleted_at?->toDateTimeImmutable(),
            sharedBudgetId: $this->shared_budget_id,
        );
    }
}
