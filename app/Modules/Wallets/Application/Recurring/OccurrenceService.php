<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Application\Activity\WalletActivity;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Categories\WalletCategoryUses;
use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceView;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Recurring\OccurrencePlan;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRules;
use App\Modules\Wallets\Domain\Recurring\OccurrenceStatus;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Recurring\Refusals\OccurrenceNotFound;
use App\Modules\Wallets\Domain\Recurring\Refusals\OccurrenceVersionConflict;
use App\Modules\Wallets\Domain\Recurring\Refusals\RecurringStateInvalid;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionStateInvalid;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use Closure;
use RuntimeException;

/**
 * Every write to an occurrence (spec 014, FR-014 to FR-016): edit one,
 * confirm, skip, un-skip. It never creates (ADR-0018 rule 1): an id the
 * server did not mint is not-found. Each write is one unit of work under the
 * fixed lock order of RecurringLocks, serialised with the job and every other
 * member on the rule row and the occurrence's version, so the second of two
 * racing confirmations is a conflict carrying the confirmed occurrence.
 * Confirming records the transaction through `TransactionService` in the same
 * unit of work.
 *
 * Precedence: not-found, forbidden, a write to a tombstone, an identical
 * replay, a stale version, forbidden in a transfer's destination, then the
 * state rules, collected into one refusal.
 *
 * @phpstan-import-type Values from OccurrenceRecord
 */
final class OccurrenceService
{
    public function __construct(
        private readonly OccurrenceRepository $occurrences,
        private readonly MembershipRepository $members,
        private readonly RecurringLocks $locks,
        private readonly RecurringStamps $stamps,
        private readonly RecurringCategoryChecks $categoryChecks,
        private readonly CategoryReferences $references,
        private readonly WalletCategoryUses $categoryUses,
        private readonly TransactionService $transactions,
        private readonly WalletAuthorizer $authorizer,
        private readonly WalletActivity $activity,
    ) {}

    /**
     * @param  Values  $values
     * @param  Closure(OccurrenceView): array<string, mixed>  $present  how a read renders the occurrence, for a conflict's `current`
     */
    public function save(string $actorId, string $walletId, string $occurrenceId, array $values, int $version, Closure $present): OccurrenceView
    {
        /** @var Values $values */
        $values = self::normalise($values);

        $seen = $this->occurrences->find($occurrenceId);

        if ($seen === null) {
            $this->authorizer->memberRole($actorId, $walletId);

            throw new OccurrenceNotFound;
        }

        return $this->locks->run(
            $actorId,
            $walletId,
            $seen->recurringRuleId,
            $occurrenceId,
            [$values['category_id']],
            null,
            null,
            function (?RecurringRuleRecord $rule, ?OccurrenceRecord $stored, array $wallets) use ($actorId, $walletId, $values, $version, $present): OccurrenceView {
                $this->checkPath($actorId, $walletId, $rule, $stored, $wallets);
                $stored ?? throw new OccurrenceNotFound;

                if ($stored->isTombstone()) {
                    throw new OccurrenceVersionConflict($present(new OccurrenceView($stored)));
                }

                if ($values === $stored->writableValues()) {
                    return new OccurrenceView($stored);
                }

                if ($version !== $stored->version) {
                    throw new OccurrenceVersionConflict($present(new OccurrenceView($stored)));
                }

                return $this->write($actorId, $rule ?? throw new OccurrenceNotFound, $stored, $values, $wallets);
            },
            function () use ($walletId, $occurrenceId, $present): never {
                $current = $this->occurrences->find($occurrenceId);

                if ($current === null || $current->walletId !== $walletId) {
                    throw new OccurrenceNotFound;
                }

                throw new OccurrenceVersionConflict($present(new OccurrenceView($current)));
            },
        );
    }

    /**
     * Not-found, then forbidden, on the path wallet, the occurrence and its rule.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function checkPath(string $actorId, string $walletId, ?RecurringRuleRecord $rule, ?OccurrenceRecord $stored, array $wallets): void
    {
        $wallet = $wallets[$walletId] ?? null;

        if ($wallet === null || $wallet->isTombstone()) {
            throw new OccurrenceNotFound;
        }

        $this->authorizer->memberRole($actorId, $walletId);

        if ($stored === null || $stored->walletId !== $walletId || $rule === null || $rule->walletId !== $walletId) {
            throw new OccurrenceNotFound;
        }

        $this->authorizer->authorize($actorId, $walletId, Permission::R02WriteRecurring);
    }

    /**
     * @param  Values  $values
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function write(string $actorId, RecurringRuleRecord $rule, OccurrenceRecord $stored, array $values, array $wallets): OccurrenceView
    {
        $confirming = $values['status'] === OccurrenceStatus::Confirmed->value && $stored->status !== OccurrenceStatus::Confirmed;

        if ($confirming) {
            $this->authorizeDestination($actorId, $stored, $wallets);
        }

        $errors = OccurrenceRules::violations($values, $stored);

        if ($stored->status !== OccurrenceStatus::Confirmed) {
            if ($stored->kind !== TransactionKind::Transfer) {
                $error = $this->categoryChecks->error($actorId, $stored->kind->value, $values['category_id'], $stored->categoryId, '/category_id');

                if ($error !== null) {
                    $errors[] = $error;
                }
            }

            if ($confirming && $values['transaction_id'] !== null && $this->occurrences->isIdTaken($values['transaction_id'])) {
                $errors[] = new FieldError('/transaction_id', ErrorCode::InvalidEnumValue);
            }
        }

        $this->refuseInvalid($errors);

        $plan = OccurrencePlan::forRound($rule, $stored->ordinal, $stored->scheduledDate, $stored->isPayout);
        $columns = [
            'status' => $values['status'],
            'date' => $values['date'],
            'amount_minor' => $values['amount_minor'],
            'category_id' => $values['category_id'],
            'description' => $values['description'],
            'memo' => $values['memo'],
            'counterparty' => $values['counterparty'],
            'transaction_id' => $values['transaction_id'],
            'edited' => $plan->isEditedBy($values),
            'auto_confirm_refusal' => null,
        ];

        if (! $this->occurrences->updateIfVersion($stored->id, $stored->version, $columns, $this->stamps->next($stored->walletId))) {
            throw new RuntimeException('The occurrence changed under its own lock.');
        }

        if ($confirming) {
            $this->confirm($actorId, $rule, $stored, $values);
        }

        if ($values['category_id'] !== $stored->categoryId) {
            if ($values['category_id'] !== null) {
                $this->references->publish($stored->walletId, $values['category_id']);
            }

            if ($stored->categoryId !== null && ! $this->categoryUses->usedIn($stored->walletId, $stored->categoryId)) {
                $this->references->withdrawIfUnused($stored->walletId, $stored->categoryId);
            }
        }

        $this->activity->record($stored->walletId, $actorId, self::actionFor($stored->status, $values['status']), ActivitySubject::Occurrence, $stored->id);
        $this->stamps->finish($stored->walletId);

        return new OccurrenceView($this->occurrences->find($stored->id) ?? throw new OccurrenceNotFound);
    }

    /**
     * What the write did to the occurrence, as activity names it: a status
     * moving to confirmed or to skipped, otherwise an edit (ADR-0020).
     */
    private static function actionFor(OccurrenceStatus $before, mixed $status): ActivityAction
    {
        return match (true) {
            $status === OccurrenceStatus::Confirmed->value && $before !== OccurrenceStatus::Confirmed => ActivityAction::Confirmed,
            $status === OccurrenceStatus::Skipped->value && $before !== OccurrenceStatus::Skipped => ActivityAction::Skipped,
            default => ActivityAction::Edited,
        };
    }

    /**
     * Records the transaction the occurrence produces, in this unit of work,
     * and maps its refusals onto the occurrence's members: a destination no
     * longer usable is `/status` INVALID_ENUM_VALUE, differing currencies
     * `/status` CURRENCY_MISMATCH (spec 014, FR-016).
     *
     * @param  Values  $values
     */
    private function confirm(string $actorId, RecurringRuleRecord $rule, OccurrenceRecord $stored, array $values): void
    {
        try {
            $this->transactions->recordForOccurrence(
                $actorId,
                $rule->createdBy,
                $stored->walletId,
                (string) $values['transaction_id'],
                [
                    'kind' => $stored->kind->value,
                    'date' => $values['date'],
                    'amount_minor' => $values['amount_minor'],
                    'currency' => $stored->currency,
                    'category_id' => $values['category_id'],
                    'counter_wallet_id' => $stored->counterWalletId,
                    'description' => $values['description'],
                    'memo' => $values['memo'],
                    'counterparty' => $values['counterparty'],
                    'cleared' => false,
                ],
                $rule->id,
                $stored->id,
            );
        } catch (TransactionStateInvalid $invalid) {
            $errors = [];

            foreach ($invalid->fieldErrors as $error) {
                $errors[] = match ($error->pointer) {
                    '/transaction_id', '/category_id' => $error,
                    default => new FieldError('/status', $error->code === ErrorCode::CurrencyMismatch ? ErrorCode::CurrencyMismatch : ErrorCode::InvalidEnumValue),
                };
            }

            $this->refuseInvalid($errors);
        }
    }

    /**
     * Confirming a transfer needs T-02 in the destination (row T-03): a
     * destination that is deleted, or that the caller is not a member of, is
     * refused later as a state rule at `/status`.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function authorizeDestination(string $actorId, OccurrenceRecord $occurrence, array $wallets): void
    {
        if ($occurrence->counterWalletId === null) {
            return;
        }

        $destination = $wallets[$occurrence->counterWalletId] ?? null;

        if ($destination === null || $destination->isTombstone() || $this->members->activeFor($destination->id, $actorId) === null) {
            return;
        }

        $this->authorizer->authorize($actorId, $destination->id, Permission::T03Transfer);
    }

    /**
     * @param  list<FieldError>  $errors
     */
    private function refuseInvalid(array $errors): void
    {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error->pointer] ??= $error;
        }

        if ($unique !== []) {
            throw new RecurringStateInvalid(array_values($unique));
        }
    }

    /**
     * Text is stored trimmed, a blank one as null.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function normalise(array $values): array
    {
        foreach (['description', 'memo', 'counterparty'] as $member) {
            if (array_key_exists($member, $values) && is_string($values[$member])) {
                $trimmed = trim($values[$member]);
                $values[$member] = $trimmed === '' ? null : $trimmed;
            }
        }

        return $values;
    }
}
