<?php

namespace App\Modules\Wallets\Application\Categories;

use App\Modules\Identity\Application\Sync\TombstonePurge as UserScopeHorizon;
use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Application\Categories\Dto\SavedCategory;
use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\CategoryRules;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryInUse;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryNotFound;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryVersionConflict;
use App\Modules\Wallets\Domain\Ports\TombstoneStore;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every write to a person's own category (spec 011, R-6). Each write is one
 * transaction under the category row lock: a change takes the next
 * change_seq of the user scope, and a change to the display fields
 * republishes every wallet reference in the same unit of work.
 *
 * LOCK ORDER (ADR-0017 rule 5): category row, then the wallets whose
 * references the write republishes or withdraws, then the owner's user row,
 * which the stamp locks until the commit.
 *
 * Precedence for every write: not-found, a write to a tombstone, an
 * identical replay, a stale version, the kind rule, in-use (delete only).
 * There are no events (P-11) and no entitlement check (P-09 rule 5).
 */
final class CategoryService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly UserScopeSequence $sequence,
        private readonly CategoryReferences $references,
        private readonly CategoryUsageQuery $usage,
        private readonly TombstoneStore $tombstones,
        private readonly UserScopeHorizon $horizon,
    ) {}

    /**
     * Creates the category (`$version` 0) or replaces its writable members.
     *
     * @param  array{kind: string, name: string, icon: ?string, color: ?string}  $values
     * @param  Closure(CategoryView): array<string, mixed>  $present  how a read renders the category, for a conflict's `current`
     */
    public function save(string $actorId, string $categoryId, array $values, int $version, Closure $present): SavedCategory
    {
        $values['name'] = CategoryRules::normaliseName($values['name']);

        return DB::transaction(function () use ($actorId, $categoryId, $values, $version, $present): SavedCategory {
            $current = $this->categories->findOwnedForUpdate($categoryId, $actorId);

            if ($current === null) {
                if ($version > 0 || $this->categories->isHeldByOther($categoryId, $actorId)) {
                    throw new CategoryNotFound;
                }

                try {
                    return $this->create($actorId, $categoryId, $values);
                } catch (UniqueConstraintViolationException) {
                    // A concurrent create of the same id won: this is a replay, or a foreign id.
                    $current = $this->categories->findOwnedForUpdate($categoryId, $actorId) ?? throw new CategoryNotFound;
                }
            }

            return new SavedCategory($this->update($current, $values, $version, $present), false);
        });
    }

    /**
     * Changes the members sent; the rest stay as stored. Never creates.
     *
     * @param  array<string, mixed>  $partial  any of name, icon, color
     * @param  Closure(CategoryView): array<string, mixed>  $present
     */
    public function patch(string $actorId, string $categoryId, array $partial, int $version, Closure $present): CategoryView
    {
        if (isset($partial['name']) && is_string($partial['name'])) {
            $partial['name'] = CategoryRules::normaliseName($partial['name']);
        }

        return DB::transaction(function () use ($actorId, $categoryId, $partial, $version, $present): CategoryView {
            $current = $this->lock($actorId, $categoryId);

            /** @var array{kind: string, name: string, icon: ?string, color: ?string} $values */
            $values = array_replace($current->writableValues(), $partial);

            return $this->update($current, $values, $version, $present);
        });
    }

    /**
     * Archives the category. One way; archiving an archived category changes nothing.
     *
     * @param  Closure(CategoryView): array<string, mixed>  $present
     */
    public function archive(string $actorId, string $categoryId, int $version, Closure $present): CategoryView
    {
        return DB::transaction(function () use ($actorId, $categoryId, $version, $present): CategoryView {
            $current = $this->lock($actorId, $categoryId);
            $this->refuseTombstone($current, $present);

            if ($current->archivedAt !== null) {
                return $this->view($current);
            }

            $this->refuseStale($current, $version, $present);

            $this->bump($current, ['archived_at' => self::instant(now()->toDateTimeImmutable())]);

            return $this->view($this->reload($categoryId));
        });
    }

    /**
     * Deletes an unused category: a tombstone. Deleting a tombstone returns it.
     *
     * @param  Closure(CategoryView): array<string, mixed>  $present
     */
    public function delete(string $actorId, string $categoryId, int $version, Closure $present): CategoryView
    {
        return DB::transaction(function () use ($actorId, $categoryId, $version, $present): CategoryView {
            $current = $this->lock($actorId, $categoryId);

            if ($current->isTombstone()) {
                return $this->view($current);
            }

            $this->refuseStale($current, $version, $present);

            if ($this->usage->isUsed($categoryId)) {
                throw new CategoryInUse;
            }

            $this->references->withdrawAll($categoryId);
            $this->bump($current, ['deleted_at' => self::instant(now()->toDateTimeImmutable())]);

            return $this->view($this->reload($categoryId));
        });
    }

    /**
     * Lets go of every category of a person whose account is being purged
     * (spec 022, FR-046, ADR-0024). One that no record refers to is
     * tombstoned (its references withdrawn, `change_seq` from the person's
     * user counter, P-07 rule 5) and, when nothing at all still points at its
     * row, removed at once with the user scope's purge horizon raised: nobody
     * reads that scope any more. One still used by a transaction, rule,
     * occurrence or budget is archived and kept with its owner id so partners
     * keep the label; the daily purge deletes it when nothing uses it. A
     * category already archived is left as it is, so a second call changes
     * nothing.
     *
     * @return array{deleted: int, kept: int}
     */
    public function releaseForDepartedUser(string $userId): array
    {
        $deleted = 0;
        $kept = 0;
        $after = null;

        do {
            $rows = $this->categories->listOwned($userId, 'created_at', false, $after, 100);
            $page = array_slice($rows, 0, 100);

            foreach ($page as $category) {
                $outcome = DB::transaction(fn (): ?string => $this->release($userId, $category->id));

                $deleted += $outcome === 'deleted' ? 1 : 0;
                $kept += $outcome === 'kept' ? 1 : 0;
            }

            $last = $page === [] ? null : $page[array_key_last($page)];
            $after = count($rows) > 100 && $last !== null ? ['value' => $this->categories->sortValue($last, 'created_at'), 'id' => $last->id] : null;
        } while ($after !== null);

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /**
     * @return 'deleted'|'kept'|null null when there was nothing to do
     */
    private function release(string $userId, string $categoryId): ?string
    {
        $current = $this->categories->findOwnedForUpdate($categoryId, $userId);

        if ($current === null || $current->isTombstone()) {
            return null;
        }

        if ($this->usage->isUsed($categoryId)) {
            if ($current->archivedAt === null) {
                $this->bump($current, ['archived_at' => self::instant(now()->toDateTimeImmutable())]);
            }

            return 'kept';
        }

        $this->references->withdrawAll($categoryId);
        $this->bump($current, ['deleted_at' => self::instant(now()->toDateTimeImmutable())]);

        $gone = $this->categories->findAny($categoryId) ?? throw new CategoryNotFound;
        $this->tombstones->deleteUnreferencedCategories([$categoryId], now()->addMinute()->toDateTimeImmutable());
        $this->horizon->raiseHorizon($userId, $gone->changeSeq);

        return 'deleted';
    }

    /**
     * @param  array{kind: string, name: string, icon: ?string, color: ?string}  $values
     */
    private function create(string $actorId, string $categoryId, array $values): SavedCategory
    {
        // A savepoint, so a lost id race does not abort the outer transaction.
        DB::transaction(fn () => $this->categories->insert($categoryId, $actorId, $values, $this->sequence->next($actorId)));

        return new SavedCategory($this->view($this->reload($categoryId)), true);
    }

    /**
     * The shared update path of save and patch, on the locked category.
     *
     * @param  array{kind: string, name: string, icon: ?string, color: ?string}  $values  the full writable values wanted
     * @param  Closure(CategoryView): array<string, mixed>  $present
     */
    private function update(CategoryRecord $current, array $values, int $version, Closure $present): CategoryView
    {
        $this->refuseTombstone($current, $present);

        if (CategoryRules::isIdentical($current, $values)) {
            return $this->view($current);
        }

        $this->refuseStale($current, $version, $present);

        CategoryRules::assertKindUnchanged($current, CategoryKind::from($values['kind']));

        // Wallets before the user counter (ADR-0017 rule 5): the references are
        // republished, locking each wallet, before bump() takes the owner's stamp.
        if ($values['name'] !== $current->name || $values['icon'] !== $current->icon || $values['color'] !== $current->color) {
            $this->references->republish(new CategoryRecord(
                $current->id, $current->ownerId, $current->kind, $values['name'], $values['icon'], $values['color'],
                $current->archivedAt, $current->version, $current->changeSeq, $current->createdAt, $current->updatedAt, $current->deletedAt,
            ));
        }

        $this->bump($current, ['name' => $values['name'], 'icon' => $values['icon'], 'color' => $values['color']]);

        $saved = $this->reload($current->id);

        return $this->view($saved);
    }

    private function lock(string $actorId, string $categoryId): CategoryRecord
    {
        return $this->categories->findOwnedForUpdate($categoryId, $actorId) ?? throw new CategoryNotFound;
    }

    /**
     * @param  Closure(CategoryView): array<string, mixed>  $present
     */
    private function refuseTombstone(CategoryRecord $current, Closure $present): void
    {
        if ($current->isTombstone()) {
            throw new CategoryVersionConflict($present($this->view($current)));
        }
    }

    /**
     * @param  Closure(CategoryView): array<string, mixed>  $present
     */
    private function refuseStale(CategoryRecord $current, int $version, Closure $present): void
    {
        if ($version !== $current->version) {
            throw new CategoryVersionConflict($present($this->view($current)));
        }
    }

    /**
     * @param  array<string, scalar|null>  $columns
     */
    private function bump(CategoryRecord $current, array $columns): void
    {
        if (! $this->categories->updateIfVersion($current->id, $current->version, $columns, $this->sequence->next($current->ownerId))) {
            throw new RuntimeException('The category changed under its own lock.');
        }
    }

    private function reload(string $categoryId): CategoryRecord
    {
        return $this->categories->findAny($categoryId) ?? throw new CategoryNotFound;
    }

    private function view(CategoryRecord $category): CategoryView
    {
        return new CategoryView($category, $this->usage->isUsed($category->id));
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
