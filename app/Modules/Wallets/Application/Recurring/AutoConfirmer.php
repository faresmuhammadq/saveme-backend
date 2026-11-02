<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Activity\WalletActivity;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Recurring\AutoConfirmRefusal;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionNotFound;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionStateInvalid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Confirms the due occurrences of an active automatic rule as the rule's
 * author (spec 014, FR-017, FR-018, research R-11): each `scheduled`,
 * unrefused occurrence dated from the rule's auto-confirm start through its
 * today, oldest first, at most `max_confirms_per_pass`. It is the same
 * transition a member's confirmation makes — the occurrence takes a new
 * version and a server-minted transaction id, and the transaction is
 * recorded through `TransactionService::recordForOccurrence` — under the row
 * locks of the rule, which serialise it with a member's confirmation.
 *
 * A refusal (the author lacks T-02 here or in a transfer's destination, the
 * destination is gone, the currencies differ) leaves the occurrence
 * scheduled with the reason set, in a savepoint so the rest of the pass
 * continues, and is not tried again until a member's save clears it.
 *
 * Must run inside the write's unit of work, with the rule and its wallets
 * locked.
 */
final class AutoConfirmer
{
    public function __construct(
        private readonly OccurrenceRepository $occurrences,
        private readonly TransactionService $transactions,
        private readonly RecurringStamps $stamps,
        private readonly WalletActivity $activity,
    ) {}

    /**
     * @return array{confirmed: int, refused: int}
     */
    public function confirmDue(RecurringRuleRecord $rule, string $today): array
    {
        $result = ['confirmed' => 0, 'refused' => 0];

        if ($rule->status !== RuleStatus::Active || ! $rule->autoConfirm || $rule->isTombstone()) {
            return $result;
        }

        $due = $this->occurrences->dueForAutoConfirm($rule->id, $rule->autoConfirmFrom, $today, (int) config('wallets.recurring.max_confirms_per_pass'));

        foreach ($due as $occurrence) {
            $refusal = $this->confirm($rule, $occurrence);

            if ($refusal === null) {
                $result['confirmed']++;

                continue;
            }

            $this->occurrences->updateIfVersion($occurrence->id, $occurrence->version, ['auto_confirm_refusal' => $refusal->value], $this->stamps->next($rule->walletId));
            $result['refused']++;
        }

        return $result;
    }

    /**
     * @return AutoConfirmRefusal|null null when it was confirmed
     */
    private function confirm(RecurringRuleRecord $rule, OccurrenceRecord $occurrence): ?AutoConfirmRefusal
    {
        try {
            DB::transaction(function () use ($rule, $occurrence): void {
                $transactionId = (string) Str::uuid7();

                $confirmed = $this->occurrences->updateIfVersion(
                    $occurrence->id,
                    $occurrence->version,
                    ['status' => 'confirmed', 'transaction_id' => $transactionId],
                    $this->stamps->next($rule->walletId),
                );

                if (! $confirmed) {
                    throw new RuntimeException('The occurrence changed under its rule\'s lock.');
                }

                $this->transactions->recordForOccurrence(
                    $rule->createdBy,
                    $rule->createdBy,
                    $rule->walletId,
                    $transactionId,
                    [
                        'kind' => $occurrence->kind->value,
                        'date' => $occurrence->date,
                        'amount_minor' => $occurrence->amountMinor,
                        'currency' => $occurrence->currency,
                        'category_id' => $occurrence->categoryId,
                        'counter_wallet_id' => $occurrence->counterWalletId,
                        'description' => $occurrence->description,
                        'memo' => $occurrence->memo,
                        'counterparty' => $occurrence->counterparty,
                        'cleared' => false,
                        'shared_budget_id' => $occurrence->isPayout ? null : $rule->sharedBudgetId,
                    ],
                    $rule->id,
                    $occurrence->id,
                );

                $this->activity->record($rule->walletId, null, ActivityAction::Confirmed, ActivitySubject::Occurrence, $occurrence->id);
            });
        } catch (WalletActionForbidden|WalletNotFound|TransactionNotFound) {
            return AutoConfirmRefusal::NotPermitted;
        } catch (TransactionStateInvalid $invalid) {
            foreach ($invalid->fieldErrors as $error) {
                if ($error->code === ErrorCode::CurrencyMismatch) {
                    return AutoConfirmRefusal::CurrencyMismatch;
                }
            }

            return AutoConfirmRefusal::CounterWalletUnavailable;
        }

        return null;
    }
}
