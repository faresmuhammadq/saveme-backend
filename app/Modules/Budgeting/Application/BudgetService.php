<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Application\Dto\SavedBudget;
use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Domain\BudgetRules;
use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Budgeting\Domain\Refusals\BudgetNotFound;
use App\Modules\Budgeting\Domain\Refusals\BudgetStateInvalid;
use App\Modules\Budgeting\Domain\Refusals\BudgetVersionConflict;
use App\Modules\Identity\Application\Sync\TombstonePurge as UserScopeHorizon;
use App\Modules\Identity\Application\Sync\UserScopeCounter;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Application\Categories\BudgetCategoryCheck;
use App\Modules\Wallets\Application\RecordIdentifiers;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryNotEligible;
use Closure;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every write to a person's budget (spec 015, research R-5), with spec 011's
 * discipline: a version in the body, an identical write answered unchanged, a
 * tombstone refused, nothing merged.
 *
 * LOCK ORDER (ADR-0017 rule 5): the category's share lock (create only), then
 * the owner's user counter, then the budget row. A replay or a conflict
 * found under the locks leaves the transaction by an exception, so the stamp
 * rolls back and the counter has no hole (spec 013, default 26).
 *
 * Precedence for every write: not-found, a write to a tombstone, an identical
 * replay, a stale version, the state rules. No events (P-11), no
 * entitlement check (P-09 rule 5), no log line (P-15).
 */
final class BudgetService
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly UserScopeCounter $counter,
        private readonly BudgetCategoryCheck $categories,
        private readonly RecordIdentifiers $identifiers,
        private readonly UserScopeHorizon $horizon,
    ) {}

    /**
     * Creates the budget (`$version` 0) or replaces its writable members.
     *
     * @param  array{category_id: ?string, period_type: string, amount_minor: int, currency: string}  $values
     * @param  Closure(BudgetRecord): array<string, mixed>  $present  how a read renders the budget, for a conflict's `current`
     */
    public function save(string $actorId, string $budgetId, array $values, int $version, Closure $present): SavedBudget
    {
        $stored = $this->budgets->findOwned($budgetId, $actorId);

        if ($stored === null) {
            if ($version > 0 || $this->budgets->isHeldByOther($budgetId, $actorId) || $this->identifiers->isTaken($budgetId)) {
                throw new BudgetNotFound;
            }
        } else {
            $this->refuseTombstone($stored, $present);

            if (BudgetRules::isIdentical($stored, $values)) {
                return new SavedBudget($stored, false);
            }
        }

        $created = false;

        $record = $this->transactionally(function () use ($actorId, $budgetId, $values, $version, $present, $stored, &$created): BudgetRecord {
            if ($stored === null && $this->create($actorId, $budgetId, $values)) {
                $created = true;

                return $this->reload($actorId, $budgetId);
            }

            [$current, $seq] = $this->lockedAfterStamp($actorId, $budgetId);

            return $this->update($current, $values, $version, $seq, $present);
        });

        return new SavedBudget($record, $created);
    }

    /**
     * Changes the limit. Never creates.
     *
     * @param  Closure(BudgetRecord): array<string, mixed>  $present
     */
    public function patch(string $actorId, string $budgetId, int $amountMinor, int $version, Closure $present): BudgetRecord
    {
        $stored = $this->budgets->findOwned($budgetId, $actorId) ?? throw new BudgetNotFound;
        $this->refuseTombstone($stored, $present);

        $values = [...$stored->writableValues(), 'amount_minor' => $amountMinor];

        if (BudgetRules::isIdentical($stored, $values)) {
            return $stored;
        }

        return $this->transactionally(function () use ($actorId, $budgetId, $values, $version, $present): BudgetRecord {
            [$current, $seq] = $this->lockedAfterStamp($actorId, $budgetId);

            // The limit is the only member a patch sends; the stored members win.
            return $this->update($current, [...$current->writableValues(), 'amount_minor' => $values['amount_minor']], $version, $seq, $present);
        });
    }

    /**
     * Archives the budget. One way; archiving an archived budget changes nothing.
     *
     * @param  Closure(BudgetRecord): array<string, mixed>  $present
     */
    public function archive(string $actorId, string $budgetId, int $version, Closure $present): BudgetRecord
    {
        $stored = $this->budgets->findOwned($budgetId, $actorId) ?? throw new BudgetNotFound;
        $this->refuseTombstone($stored, $present);

        if ($stored->isArchived()) {
            return $stored;
        }

        return $this->transactionally(function () use ($actorId, $budgetId, $version, $present): BudgetRecord {
            [$current, $seq] = $this->lockedAfterStamp($actorId, $budgetId);
            $this->refuseTombstone($current, $present);

            if ($current->isArchived()) {
                throw new Replay($current);
            }

            $this->refuseStale($current, $version, $present);

            return $this->bump($current, ['archived_at' => self::instant()], $seq);
        });
    }

    /**
     * Deletes the budget: a final tombstone. Deleting a tombstone returns it.
     *
     * @param  Closure(BudgetRecord): array<string, mixed>  $present
     */
    public function delete(string $actorId, string $budgetId, int $version, Closure $present): BudgetRecord
    {
        $stored = $this->budgets->findOwned($budgetId, $actorId) ?? throw new BudgetNotFound;

        if ($stored->isTombstoned()) {
            return $stored;
        }

        return $this->transactionally(function () use ($actorId, $budgetId, $version, $present): BudgetRecord {
            [$current, $seq] = $this->lockedAfterStamp($actorId, $budgetId);

            if ($current->isTombstoned()) {
                throw new Replay($current);
            }

            $this->refuseStale($current, $version, $present);

            return $this->bump($current, ['deleted_at' => self::instant()], $seq);
        });
    }

    /**
     * Removes every budget of a person whose account is being purged (spec
     * 022, FR-046, ADR-0024): each live budget is first tombstoned from the
     * person's user counter (P-07 rule 5), then every tombstone is deleted
     * and the user scope's purge horizon raised past the highest, because no
     * reader of that scope remains. Idempotent: with nothing left it does
     * nothing.
     *
     * @return int budgets removed
     */
    public function removeAllForDepartedUser(string $userId): int
    {
        do {
            $live = array_slice($this->budgets->listLive($userId, false, false, null, 100), 0, 100);

            foreach ($live as $budget) {
                DB::transaction(function () use ($budget, $userId): void {
                    $seq = $this->counter->next($userId);
                    $this->budgets->updateIfVersion($budget->id, $budget->version, ['deleted_at' => self::instant()], $seq);
                });
            }
        } while ($live !== []);

        $removed = 0;

        do {
            $tombstones = $this->budgets->changedFor($userId, 0, 200);

            $batch = DB::transaction(function () use ($tombstones, $userId): int {
                $ids = array_map(fn (BudgetRecord $budget): string => $budget->id, array_values(array_filter($tombstones, fn (BudgetRecord $budget): bool => $budget->isTombstoned())));

                if ($ids === []) {
                    return 0;
                }

                $this->horizon->raiseHorizon($userId, max(0, ...array_map(fn (BudgetRecord $budget): int => $budget->changeSeq, $tombstones)));

                return count($this->budgets->deleteTombstones($ids, now()->addMinute()->toDateTimeImmutable()));
            });

            $removed += $batch;
        } while ($batch > 0);

        return $removed;
    }

    /**
     * Runs the work in one transaction; a replay found under the locks rolls
     * the stamp back and answers the stored record.
     *
     * @param  Closure(): BudgetRecord  $work
     */
    private function transactionally(Closure $work): BudgetRecord
    {
        try {
            return DB::transaction($work);
        } catch (Replay $replay) {
            return $replay->record;
        }
    }

    /**
     * Inserts the budget under the category lock and the counter; false when
     * a concurrent create of the same id won, so the caller treats it as an update.
     *
     * @param  array{category_id: ?string, period_type: string, amount_minor: int, currency: string}  $values
     */
    private function create(string $actorId, string $budgetId, array $values): bool
    {
        if ($values['category_id'] !== null) {
            try {
                $this->categories->lockEligible($actorId, $values['category_id']);
            } catch (CategoryNotEligible) {
                throw new BudgetStateInvalid([new FieldError('/category_id', ErrorCode::InvalidEnumValue)]);
            }
        }

        try {
            // A savepoint, so a lost id race does not abort the outer transaction.
            DB::transaction(fn () => $this->budgets->insert($budgetId, $actorId, $values, $this->counter->next($actorId)));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Takes the owner's stamp, then the budget row (lock order).
     *
     * @return array{BudgetRecord, int} the locked budget and the stamp
     */
    private function lockedAfterStamp(string $actorId, string $budgetId): array
    {
        $seq = $this->counter->next($actorId);

        return [$this->budgets->findOwnedForUpdate($budgetId, $actorId) ?? throw new BudgetNotFound, $seq];
    }

    /**
     * The shared update path of save and patch, on the locked budget.
     *
     * @param  array{category_id: ?string, period_type: string, amount_minor: int, currency: string}  $values
     * @param  Closure(BudgetRecord): array<string, mixed>  $present
     */
    private function update(BudgetRecord $current, array $values, int $version, int $seq, Closure $present): BudgetRecord
    {
        $this->refuseTombstone($current, $present);

        if (BudgetRules::isIdentical($current, $values)) {
            throw new Replay($current);
        }

        $this->refuseStale($current, $version, $present);
        BudgetRules::assertImmutablesUnchanged($current, $values);

        return $this->bump($current, ['amount_minor' => $values['amount_minor']], $seq);
    }

    /**
     * @param  Closure(BudgetRecord): array<string, mixed>  $present
     */
    private function refuseTombstone(BudgetRecord $current, Closure $present): void
    {
        if ($current->isTombstoned()) {
            throw new BudgetVersionConflict($present($current));
        }
    }

    /**
     * @param  Closure(BudgetRecord): array<string, mixed>  $present
     */
    private function refuseStale(BudgetRecord $current, int $version, Closure $present): void
    {
        if ($version !== $current->version) {
            throw new BudgetVersionConflict($present($current));
        }
    }

    /**
     * @param  array<string, scalar|null>  $columns
     */
    private function bump(BudgetRecord $current, array $columns, int $seq): BudgetRecord
    {
        if (! $this->budgets->updateIfVersion($current->id, $current->version, $columns, $seq)) {
            throw new RuntimeException('The budget changed under its own lock.');
        }

        return $this->reload($current->userId, $current->id);
    }

    private function reload(string $ownerId, string $budgetId): BudgetRecord
    {
        return $this->budgets->findOwned($budgetId, $ownerId) ?? throw new BudgetNotFound;
    }

    private static function instant(): string
    {
        return now()->toDateTimeImmutable()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
