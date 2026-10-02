<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Identity\Application\Events;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Application\Activity\WalletActivity;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Categories\WalletCategoryUses;
use App\Modules\Wallets\Application\Transactions\Dto\SavedTransaction;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Events\TransactionPosted;
use App\Modules\Wallets\Domain\Events\TransactionVoided;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Transactions\Ports\PostingRepository;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;
use App\Modules\Wallets\Domain\Transactions\PostingDerivation;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionNotFound;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionStateInvalid;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionVersionConflict;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Domain\Transactions\TransactionRecord;
use App\Modules\Wallets\Domain\Transactions\TransactionRules;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Every write to a transaction (spec 012, R-3 to R-8). Each write is one
 * transaction of the database: the categories it touches are share-locked,
 * then the wallets it touches are locked in ascending id (ADR-0014 rule 10),
 * the row and its postings are written, every touched wallet's cached
 * balance is recomputed from the postings, and the sequences are stamped
 * inside the locks. Events follow the commit.
 *
 * Precedence for every write: not-found, forbidden on the path wallet, a
 * write to a tombstone, an identical replay, a stale version, forbidden on a
 * transfer's other wallet, then the state rules, collected into one refusal.
 * No amount, description, memo or counterparty is ever logged (P-15).
 *
 * @phpstan-type Values array{kind: string, date: string, amount_minor: int, currency: string, category_id: ?string, counter_wallet_id: ?string, description: ?string, memo: ?string, counterparty: ?string, cleared: bool}
 */
final class TransactionService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly PostingRepository $postings,
        private readonly WalletRepository $wallets,
        private readonly MembershipRepository $members,
        private readonly CategoryRepository $categories,
        private readonly CategoryReferences $references,
        private readonly ScopeSequence $sequence,
        private readonly PostingTotals $totals,
        private readonly WalletAuthorizer $authorizer,
        private readonly WalletCategoryUses $categoryUses,
        private readonly WalletActivity $activity,
    ) {}

    /**
     * Records the transaction (`$version` 0) or replaces its writable members.
     * For a transfer the path wallet is the source, or either of its two
     * wallets once it exists.
     *
     * @param  Values  $values
     * @param  Closure(TransactionView): array<string, mixed>  $present  how a read renders the transaction, for a conflict's `current`
     */
    public function save(string $actorId, string $walletId, string $transactionId, array $values, int $version, Closure $present): SavedTransaction
    {
        /** @var Values $values */
        $values = self::normalise($values);

        return $this->withLocks($actorId, $walletId, $transactionId, $values['category_id'], $values['counter_wallet_id'], $present, function (?TransactionRecord $stored, array $wallets) use ($actorId, $walletId, $transactionId, $values, $version, $present): SavedTransaction {
            $this->checkPath($actorId, $walletId, $transactionId, $stored, $wallets, $version, mayCreate: true);

            if ($stored === null) {
                return new SavedTransaction($this->create($actorId, $walletId, $transactionId, $values, $wallets), true);
            }

            $this->refuseTombstone($stored, $present);

            if ($values === $stored->writableValues()) {
                return new SavedTransaction(new TransactionView($stored), false);
            }

            $this->refuseStale($stored, $version, $present);

            return new SavedTransaction($this->update($actorId, $walletId, $stored, $values, $wallets), false);
        });
    }

    /**
     * Changes the members sent; the rest stay as stored. Never creates.
     *
     * @param  array<string, mixed>  $partial  any of date, amount_minor, category_id, description, memo, counterparty, cleared
     * @param  Closure(TransactionView): array<string, mixed>  $present
     */
    public function patch(string $actorId, string $walletId, string $transactionId, array $partial, int $version, Closure $present): TransactionView
    {
        $partial = self::normalise($partial);
        $category = isset($partial['category_id']) && is_string($partial['category_id']) ? $partial['category_id'] : null;

        return $this->withLocks($actorId, $walletId, $transactionId, $category, null, $present, function (?TransactionRecord $stored, array $wallets) use ($actorId, $walletId, $transactionId, $partial, $version, $present): TransactionView {
            $this->checkPath($actorId, $walletId, $transactionId, $stored, $wallets, $version, mayCreate: false);
            $stored ?? throw new TransactionNotFound;
            $this->refuseTombstone($stored, $present);

            /** @var Values $values */
            $values = array_replace($stored->writableValues(), $partial);

            if ($values === $stored->writableValues()) {
                return new TransactionView($stored);
            }

            $this->refuseStale($stored, $version, $present);

            return $this->update($actorId, $walletId, $stored, $values, $wallets);
        });
    }

    /**
     * Records the transaction a confirmed occurrence produces (spec 014, R-6,
     * ADR-0018 rule 4). It runs inside the caller's unit of work, which
     * already holds the locks in the documented order (categories, wallets
     * ascending, the rule, its occurrences): the locks are taken again here,
     * in the same order, which Postgres grants at once, and there is no
     * drift-restart loop because the caller holds them and the id is new.
     *
     * `$actorId` is who is authorised: T-02 in the wallet and in a transfer's
     * destination (a refusal is `WalletActionForbidden`, or not-found when
     * they are not a member). `$authorId` is the rule's author and becomes
     * `created_by`. Category ownership was settled when the rule or
     * occurrence was written, so only that the category is live and of the
     * right kind is checked again; so are the destination and the currency.
     * The state rules refuse with `TransactionStateInvalid`, `/transaction_id`
     * when the id turned out taken.
     *
     * @param  Values  $values  `cleared` false
     *
     * @throws LogicException when no database transaction is open
     * @throws TransactionNotFound when the wallet is not live
     * @throws WalletActionForbidden when the actor may not write in the wallet or a transfer's destination
     * @throws TransactionStateInvalid when a state rule refuses the transaction
     */
    public function recordForOccurrence(string $actorId, string $authorId, string $walletId, string $transactionId, array $values, string $ruleId, string $occurrenceId): TransactionView
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An occurrence is confirmed inside its own unit of work.');
        }

        if ($values['category_id'] !== null) {
            $this->categories->findForShare($values['category_id']) ?? $this->categories->findPlatformForShare($values['category_id']);
        }

        $walletIds = [$walletId];

        if ($values['counter_wallet_id'] !== null && $this->members->activeFor($values['counter_wallet_id'], $actorId) !== null) {
            $walletIds[] = $values['counter_wallet_id'];
        }

        sort($walletIds, SORT_STRING);

        $wallets = [];
        foreach ($walletIds as $id) {
            $wallets[$id] = $this->wallets->findForUpdate($id);
        }

        $wallet = $wallets[$walletId] ?? null;

        if ($wallet === null || $wallet->isTombstone()) {
            throw new TransactionNotFound;
        }

        $this->authorizer->authorize($actorId, $walletId, Permission::T02WriteTransactions);

        $other = $this->authorizeOther($actorId, $walletId, null, $values['counter_wallet_id'], $wallets);
        $this->assertStateRules($actorId, null, $values, $other, $wallet, $wallets, categoryOwnershipSettled: true);

        $counterId = $values['counter_wallet_id'];

        try {
            // A savepoint, so a lost id race does not abort the caller's transaction.
            DB::transaction(fn () => $this->transactions->insert(
                $transactionId,
                $walletId,
                $values,
                $authorId,
                $this->sequence->nextFor($walletId),
                $counterId === null ? null : $this->sequence->nextFor($counterId),
                $ruleId,
                $occurrenceId,
            ));
        } catch (UniqueConstraintViolationException) {
            throw new TransactionStateInvalid([new FieldError('/transaction_id', ErrorCode::InvalidEnumValue)]);
        }

        $saved = $this->reload($transactionId);
        $this->postings->replaceFor($saved->id, PostingDerivation::for($saved));

        if ($saved->categoryId !== null) {
            $this->references->publish($walletId, $saved->categoryId);
        }

        $this->settle($saved->walletIds(), $wallets);

        event(new TransactionPosted(...Events::stamp(), transactionId: $saved->id, walletId: $saved->walletId, counterWalletId: $saved->counterWalletId, kind: $saved->kind->value, date: $saved->date, previousDate: null, categoryId: $saved->categoryId, previousCategoryId: null, actorUserId: $actorId, change: 'recorded'));

        return new TransactionView($saved);
    }

    /**
     * Undeletes a tombstone: live again at version + 1, postings re-derived.
     * Restoring a live transaction changes nothing.
     *
     * @param  Closure(TransactionView): array<string, mixed>  $present
     */
    public function restore(string $actorId, string $walletId, string $transactionId, int $version, Closure $present): TransactionView
    {
        return $this->withLocks($actorId, $walletId, $transactionId, null, null, $present, function (?TransactionRecord $stored, array $wallets) use ($actorId, $walletId, $transactionId, $version, $present): TransactionView {
            $this->checkPath($actorId, $walletId, $transactionId, $stored, $wallets, $version, mayCreate: false);
            $stored ?? throw new TransactionNotFound;

            if (! $stored->isTombstone()) {
                return new TransactionView($stored);
            }

            $this->refuseStale($stored, $version, $present);

            $values = $stored->writableValues();
            $other = $this->authorizeOther($actorId, $walletId, $stored, null, $wallets);
            $this->assertStateRules($actorId, $stored, $values, $other, $wallets[$stored->walletId] ?? null, $wallets);

            [$changeSeq, $counterChangeSeq] = $this->stamps($stored, $wallets);

            if (! $this->transactions->updateIfVersion($stored->id, $stored->version, ['deleted_at' => null], $changeSeq, $counterChangeSeq)) {
                throw new RuntimeException('The transaction changed under its own lock.');
            }

            $saved = $this->reload($stored->id);
            $this->postings->replaceFor($saved->id, PostingDerivation::for($saved));
            $this->settle($saved->walletIds(), $wallets);
            $this->recordActivity($actorId, $saved, ActivityAction::Restored);

            event(new TransactionPosted(...Events::stamp(), transactionId: $saved->id, walletId: $saved->walletId, counterWalletId: $saved->counterWalletId, kind: $saved->kind->value, date: $saved->date, previousDate: null, categoryId: $saved->categoryId, previousCategoryId: null, actorUserId: $actorId, change: 'restored'));

            return new TransactionView($saved);
        });
    }

    /**
     * Deletes the transaction: a tombstone, its postings removed from every
     * balance. Deleting a tombstone returns it.
     *
     * @param  Closure(TransactionView): array<string, mixed>  $present
     */
    public function delete(string $actorId, string $walletId, string $transactionId, int $version, Closure $present): TransactionView
    {
        return $this->withLocks($actorId, $walletId, $transactionId, null, null, $present, function (?TransactionRecord $stored, array $wallets) use ($actorId, $walletId, $transactionId, $version, $present): TransactionView {
            $this->checkPath($actorId, $walletId, $transactionId, $stored, $wallets, $version, mayCreate: false);
            $stored ?? throw new TransactionNotFound;

            if ($stored->isTombstone()) {
                return new TransactionView($stored);
            }

            $this->refuseStale($stored, $version, $present);
            $this->authorizeOtherForDelete($actorId, $walletId, $stored, $wallets);

            [$changeSeq, $counterChangeSeq] = $this->stamps($stored, $wallets);

            if (! $this->transactions->updateIfVersion($stored->id, $stored->version, ['deleted_at' => self::instant(now()->toDateTimeImmutable())], $changeSeq, $counterChangeSeq)) {
                throw new RuntimeException('The transaction changed under its own lock.');
            }

            $this->postings->removeFor($stored->id);
            $this->settle($stored->walletIds(), $wallets);
            $this->recordActivity($actorId, $stored, ActivityAction::Deleted);

            event(new TransactionVoided(...Events::stamp(), transactionId: $stored->id, walletId: $stored->walletId, counterWalletId: $stored->counterWalletId, kind: $stored->kind->value, date: $stored->date, categoryId: $stored->categoryId, actorUserId: $actorId));

            return new TransactionView($this->reload($stored->id));
        });
    }

    /**
     * Runs the body in one unit of work holding every lock it needs: the
     * categories first (share, ascending), then the wallets (update,
     * ascending), then the transaction row. When a concurrent write moved the
     * transaction between the unlocked read and the locks, starts again, at
     * most three times, then answers with the stored transaction.
     *
     * @template T
     *
     * @param  Closure(TransactionView): array<string, mixed>  $present
     * @param  Closure(?TransactionRecord, array<string, ?WalletRecord>): T  $body
     * @return T
     */
    private function withLocks(string $actorId, string $walletId, string $transactionId, ?string $requestedCategoryId, ?string $requestedCounterId, Closure $present, Closure $body): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($actorId, $walletId, $transactionId, $requestedCategoryId, $requestedCounterId, $body) {
                    $seen = $this->transactions->find($transactionId);

                    $categoryIds = array_values(array_unique(array_filter([$seen?->categoryId, $requestedCategoryId])));
                    sort($categoryIds, SORT_STRING);

                    foreach ($categoryIds as $categoryId) {
                        $this->categories->findForShare($categoryId) ?? $this->categories->findPlatformForShare($categoryId);
                    }

                    $walletIds = [$walletId, ...($seen?->walletIds() ?? [])];

                    if ($requestedCounterId !== null && $this->members->activeFor($requestedCounterId, $actorId) !== null) {
                        $walletIds[] = $requestedCounterId;
                    }

                    $walletIds = array_values(array_unique($walletIds));
                    sort($walletIds, SORT_STRING);

                    $wallets = [];
                    foreach ($walletIds as $id) {
                        $wallets[$id] = $this->wallets->findForUpdate($id);
                    }

                    $stored = $this->transactions->findForUpdate($transactionId);

                    if (self::lockSignature($stored) !== self::lockSignature($seen)) {
                        throw new DriftedUnderLock;
                    }

                    return $body($stored, $wallets);
                });
            } catch (DriftedUnderLock) {
                // A concurrent write landed between the read and the locks: go again.
            }
        }

        $current = $this->transactions->find($transactionId);

        if ($current === null || ! $current->touches($walletId)) {
            throw new TransactionNotFound;
        }

        throw new TransactionVersionConflict($present(new TransactionView($current)));
    }

    /**
     * @return array{?string, ?string, ?string, bool}|null
     */
    private static function lockSignature(?TransactionRecord $transaction): ?array
    {
        return $transaction === null ? null : [$transaction->walletId, $transaction->counterWalletId, $transaction->categoryId, $transaction->isTombstone()];
    }

    /**
     * Not-found, then forbidden, on the path wallet and the transaction id.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function checkPath(string $actorId, string $walletId, string $transactionId, ?TransactionRecord $stored, array $wallets, int $version, bool $mayCreate): void
    {
        $wallet = $wallets[$walletId] ?? null;

        if ($wallet === null || $wallet->isTombstone()) {
            throw new TransactionNotFound;
        }

        $this->authorizer->memberRole($actorId, $walletId);

        if ($stored === null) {
            if (! $mayCreate || $version > 0 || $this->transactions->isHeldByOther($transactionId, $walletId)) {
                throw new TransactionNotFound;
            }
        } elseif (! $stored->touches($walletId)) {
            throw new TransactionNotFound;
        }

        $this->authorizer->authorize($actorId, $walletId, Permission::T02WriteTransactions);
    }

    /**
     * @param  Values  $values
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function create(string $actorId, string $walletId, string $transactionId, array $values, array $wallets): TransactionView
    {
        $other = $this->authorizeOther($actorId, $walletId, null, $values['counter_wallet_id'], $wallets);
        $this->assertStateRules($actorId, null, $values, $other, $wallets[$walletId] ?? null, $wallets);

        $counterId = $values['counter_wallet_id'];

        try {
            // A savepoint, so a lost id race does not abort the outer transaction.
            DB::transaction(fn () => $this->transactions->insert(
                $transactionId,
                $walletId,
                $values,
                $actorId,
                $this->sequence->nextFor($walletId),
                $counterId === null ? null : $this->sequence->nextFor($counterId),
            ));
        } catch (UniqueConstraintViolationException) {
            // The same id was created concurrently from another wallet's path: look again.
            throw new DriftedUnderLock;
        }

        $saved = $this->reload($transactionId);
        $this->postings->replaceFor($saved->id, PostingDerivation::for($saved));

        if ($saved->categoryId !== null) {
            $this->references->publish($walletId, $saved->categoryId);
        }

        $this->settle($saved->walletIds(), $wallets);

        $this->recordActivity($actorId, $saved, ActivityAction::Created);

        event(new TransactionPosted(...Events::stamp(), transactionId: $saved->id, walletId: $saved->walletId, counterWalletId: $saved->counterWalletId, kind: $saved->kind->value, date: $saved->date, previousDate: null, categoryId: $saved->categoryId, previousCategoryId: null, actorUserId: $actorId, change: 'recorded'));

        return new TransactionView($saved);
    }

    /**
     * The shared update path of save and patch, on the locked live transaction.
     *
     * @param  Values  $values  the full writable values wanted
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function update(string $actorId, string $walletId, TransactionRecord $stored, array $values, array $wallets): TransactionView
    {
        $other = $this->authorizeOther($actorId, $walletId, $stored, null, $wallets);
        $this->assertStateRules($actorId, $stored, $values, $other, $wallets[$stored->walletId] ?? null, $wallets);

        [$changeSeq, $counterChangeSeq] = $this->stamps($stored, $wallets);

        $columns = [
            'date' => $values['date'],
            'amount_minor' => $values['amount_minor'],
            'category_id' => $values['category_id'],
            'description' => $values['description'],
            'memo' => $values['memo'],
            'counterparty' => $values['counterparty'],
            'cleared' => $values['cleared'],
        ];

        if (! $this->transactions->updateIfVersion($stored->id, $stored->version, $columns, $changeSeq, $counterChangeSeq)) {
            throw new RuntimeException('The transaction changed under its own lock.');
        }

        $saved = $this->reload($stored->id);
        $this->postings->replaceFor($saved->id, PostingDerivation::for($saved));

        if ($saved->categoryId !== $stored->categoryId) {
            $this->moveReference($stored, $saved);
        }

        $this->settle($saved->walletIds(), $wallets);
        $this->recordActivity($actorId, $saved, ActivityAction::Edited);

        event(new TransactionPosted(...Events::stamp(), transactionId: $saved->id, walletId: $saved->walletId, counterWalletId: $saved->counterWalletId, kind: $saved->kind->value, date: $saved->date, previousDate: $stored->date, categoryId: $saved->categoryId, previousCategoryId: $stored->categoryId, actorUserId: $actorId, change: 'amended'));

        return new TransactionView($saved);
    }

    /**
     * One activity entry per wallet the transaction touches, so the members
     * of a transfer's other wallet see it too (ADR-0020). Identifiers only.
     */
    private function recordActivity(string $actorId, TransactionRecord $transaction, ActivityAction $action): void
    {
        foreach ($transaction->walletIds() as $walletId) {
            $this->activity->record($walletId, $actorId, $action, ActivitySubject::Transaction, $transaction->id);
        }
    }

    /**
     * Publishes the reference to the new category, and withdraws the old
     * one's when nothing in the wallet, live or tombstoned, still uses it: no
     * other transaction, and no recurring rule or occurrence (spec 012, R-8,
     * spec 014, R-7).
     */
    private function moveReference(TransactionRecord $before, TransactionRecord $after): void
    {
        if ($after->categoryId !== null) {
            $this->references->publish($after->walletId, $after->categoryId);
        }

        if ($before->categoryId !== null && ! $this->categoryUses->usedIn($before->walletId, $before->categoryId, $before->id)) {
            $this->references->withdrawIfUnused($before->walletId, $before->categoryId);
        }
    }

    /**
     * The transfer's other wallet, relative to the path, when it is a live
     * wallet the caller is a member of; refuses a viewer there (row T-03),
     * and a non-member of a stored transfer's source when the path names
     * its destination (`counter_wallet_id` is then the caller's own wallet,
     * so no field is wrong). Null for income and expense, a deleted other
     * wallet, and a destination the caller is not a member of: FR-006
     * refuses that as a state rule at `/counter_wallet_id`.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function authorizeOther(string $actorId, string $walletId, ?TransactionRecord $stored, ?string $requestedCounterId, array $wallets): ?WalletRecord
    {
        $otherId = $stored === null
            ? $requestedCounterId
            : ($stored->isTransfer() ? ($stored->walletId === $walletId ? $stored->counterWalletId : $stored->walletId) : null);

        $other = $otherId === null ? null : ($wallets[$otherId] ?? null);

        if ($other === null || $other->isTombstone() || $other->id === $walletId) {
            return null;
        }

        if ($this->members->activeFor($other->id, $actorId) === null) {
            if ($stored !== null && $stored->walletId === $other->id) {
                throw new WalletActionForbidden;
            }

            return null;
        }

        $this->authorizer->authorize($actorId, $other->id, Permission::T03Transfer);

        return $other;
    }

    /**
     * Delete removes the transfer's effect from the other wallet too, so the
     * caller needs row T-03 there; a deleted other wallet needs nothing.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function authorizeOtherForDelete(string $actorId, string $walletId, TransactionRecord $stored, array $wallets): void
    {
        if (! $stored->isTransfer()) {
            return;
        }

        $otherId = $stored->walletId === $walletId ? $stored->counterWalletId : $stored->walletId;
        $other = $otherId === null ? null : ($wallets[$otherId] ?? null);

        if ($other === null || $other->isTombstone()) {
            return;
        }

        if ($this->members->activeFor($other->id, $actorId) === null) {
            throw new WalletActionForbidden;
        }

        $this->authorizer->authorize($actorId, $other->id, Permission::T03Transfer);
    }

    /**
     * The state rules, collected into one refusal (spec 012, section 5).
     *
     * @param  Values  $values
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function assertStateRules(string $actorId, ?TransactionRecord $stored, array $values, ?WalletRecord $other, ?WalletRecord $source, array $wallets, bool $categoryOwnershipSettled = false): void
    {
        $source ?? throw new TransactionNotFound;

        $destinationId = $values['counter_wallet_id'];
        $destination = $destinationId === null ? null : ($wallets[$destinationId] ?? null);
        $destinationCurrency = $destination !== null && ! $destination->isTombstone() ? $destination->currency : null;

        $errors = TransactionRules::violations($values, $stored, $source->currency, $destinationCurrency);

        if ($values['kind'] === TransactionKind::Transfer->value && $destinationId !== null && $other === null) {
            $errors[] = new FieldError('/counter_wallet_id', ErrorCode::InvalidEnumValue);
        }

        $categoryError = $this->categoryError($actorId, $stored, $values, $categoryOwnershipSettled);

        if ($categoryError !== null) {
            $errors[] = $categoryError;
        }

        $unique = [];
        foreach ($errors as $error) {
            $unique[$error->pointer] ??= $error;
        }

        if ($unique !== []) {
            throw new TransactionStateInvalid(array_values($unique));
        }
    }

    /**
     * Income and expense need a category the caller may use: their own or a
     * platform one, live, of the same kind. One that is already on the
     * transaction stays, whoever's it is (spec 010 T-02, spec 011). With
     * `$ownershipSettled` (a confirmed occurrence, spec 014 A-24) whoever's it
     * is is accepted, and it must still be live and of the same kind.
     *
     * @param  Values  $values
     */
    private function categoryError(string $actorId, ?TransactionRecord $stored, array $values, bool $ownershipSettled = false): ?FieldError
    {
        $categoryId = $values['category_id'];

        if ($values['kind'] === TransactionKind::Transfer->value || $categoryId === null || $stored?->categoryId === $categoryId) {
            return null;
        }

        $platform = $this->categories->findPlatformForShare($categoryId);

        if ($platform !== null) {
            return $platform->kind->value === $values['kind'] ? null : new FieldError('/category_id', ErrorCode::InvalidEnumValue);
        }

        $own = $this->categories->findForShare($categoryId);

        if ($own !== null && ($ownershipSettled || $own->ownerId === $actorId) && ! $own->isTombstone() && $own->kind->value === $values['kind']) {
            return null;
        }

        return new FieldError('/category_id', ErrorCode::InvalidEnumValue);
    }

    /**
     * The transaction's change_seq from its home wallet's counter and, for a
     * transfer, counter_change_seq from the destination's (ADR-0015). A
     * deleted wallet's counter is never written.
     *
     * @param  array<string, ?WalletRecord>  $wallets
     * @return array{?int, ?int}
     */
    private function stamps(TransactionRecord $transaction, array $wallets): array
    {
        $changeSeq = self::isLive($transaction->walletId, $wallets) ? $this->sequence->nextFor($transaction->walletId) : null;
        $counterChangeSeq = $transaction->counterWalletId !== null && self::isLive($transaction->counterWalletId, $wallets)
            ? $this->sequence->nextFor($transaction->counterWalletId)
            : null;

        return [$changeSeq, $counterChangeSeq];
    }

    /**
     * Recomputes every touched live wallet's cached balance from its
     * postings, then gives each its final change_seq of the write, the
     * highest in its scope (spec 010 R-3). A deleted wallet is not written.
     *
     * @param  list<string>  $walletIds
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private function settle(array $walletIds, array $wallets): void
    {
        $live = array_values(array_filter(array_unique($walletIds), fn (string $id): bool => self::isLive($id, $wallets)));
        sort($live, SORT_STRING);

        foreach ($live as $id) {
            $this->wallets->refreshBalance($id, $this->totals->sumFor($id));
        }

        foreach ($live as $id) {
            $this->sequence->nextFor($id);
        }
    }

    /**
     * @param  array<string, ?WalletRecord>  $wallets
     */
    private static function isLive(string $walletId, array $wallets): bool
    {
        $wallet = $wallets[$walletId] ?? null;

        return $wallet !== null && ! $wallet->isTombstone();
    }

    /**
     * @param  Closure(TransactionView): array<string, mixed>  $present
     */
    private function refuseTombstone(TransactionRecord $stored, Closure $present): void
    {
        if ($stored->isTombstone()) {
            throw new TransactionVersionConflict($present(new TransactionView($stored)));
        }
    }

    /**
     * @param  Closure(TransactionView): array<string, mixed>  $present
     */
    private function refuseStale(TransactionRecord $stored, int $version, Closure $present): void
    {
        if ($version !== $stored->version) {
            throw new TransactionVersionConflict($present(new TransactionView($stored)));
        }
    }

    private function reload(string $transactionId): TransactionRecord
    {
        return $this->transactions->find($transactionId) ?? throw new TransactionNotFound;
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

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
