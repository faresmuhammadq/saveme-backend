<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Application\SharedBudgets\Dto\SavedSharedBudget;
use App\Modules\Budgeting\Application\SharedBudgets\Dto\SavedSharedBudgetLine;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetActionForbidden;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetStateInvalid;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetVersionConflict;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRole;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRules;
use App\Modules\Identity\Application\Profiles\BudgetStartDayQuery;
use App\Modules\Identity\Application\Sync\UserScopeCounter;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Application\Categories\BudgetCategoryCheck;
use App\Modules\Wallets\Application\RecordIdentifiers;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryNotEligible;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every write to a Shared Budget, its lines and its members (ADR-0027), with
 * the discipline of spec 015: a version in the body, an identical write
 * answered unchanged, a tombstone refused, nothing merged.
 *
 * LOCK ORDER (ADR-0027 rule 10): a category's share lock, the budget row, user
 * rows in ascending id, then the records. Every unit of work ends by taking
 * one more stamp, so the budget row holds the highest position in its scope.
 */
final class SharedBudgetService
{
    public function __construct(
        private readonly SharedBudgetRepository $budgets,
        private readonly SharedBudgetAuthorizer $authorizer,
        private readonly SharedBudgetPeriods $periods,
        private readonly BudgetCategoryCheck $categories,
        private readonly BudgetStartDayQuery $profiles,
        private readonly UserScopeCounter $userCounter,
        private readonly RecordIdentifiers $identifiers,
    ) {}

    /**
     * Creates the budget (`$version` 0) or replaces its writable members.
     *
     * @param  array{name: string, currency: string, start_day: int, limit_minor: ?int}  $values
     * @param  Closure(SharedBudgetRecord): array<string, mixed>  $present  how a read renders the budget
     */
    public function save(string $actorId, string $budgetId, array $values, int $version, Closure $present): SavedSharedBudget
    {
        $stored = $this->budgets->find($budgetId);

        if ($stored === null) {
            if ($version > 0 || $this->budgets->identifierTaken($budgetId) || $this->identifiers->isTaken($budgetId)) {
                throw new SharedBudgetNotFound;
            }

            try {
                return new SavedSharedBudget($this->create($actorId, $budgetId, $values), true);
            } catch (UniqueConstraintViolationException) {
                $stored = $this->budgets->find($budgetId) ?? throw new SharedBudgetNotFound;
            }
        }

        return new SavedSharedBudget($this->change($actorId, $stored, $values, $version, $present), false);
    }

    /**
     * Changes the members sent; the rest stay as stored. Never creates.
     *
     * @param  array<string, mixed>  $partial  any of name, start_day, limit_minor
     * @param  Closure(SharedBudgetRecord): array<string, mixed>  $present
     */
    public function patch(string $actorId, string $budgetId, array $partial, int $version, Closure $present): SharedBudgetRecord
    {
        $stored = $this->budgets->find($budgetId) ?? throw new SharedBudgetNotFound;

        /** @var array{name: string, currency: string, start_day: int, limit_minor: ?int} $values */
        $values = array_replace($stored->writableValues(), $partial);

        return $this->change($actorId, $stored, $values, $version, $present);
    }

    /**
     * Archives the budget. One way: it stops taking transactions and keeps its history.
     *
     * @param  Closure(SharedBudgetRecord): array<string, mixed>  $present
     */
    public function archive(string $actorId, string $budgetId, int $version, Closure $present): SharedBudgetRecord
    {
        return DB::transaction(function () use ($actorId, $budgetId, $version, $present): SharedBudgetRecord {
            $current = $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;
            $this->authorizer->owner($actorId, $budgetId);

            if ($current->isArchived()) {
                return $current;
            }

            $this->refuseStale($current, $version, $present);
            $this->budgets->update($budgetId, ['archived_at' => $this->budgets->now()->format('Y-m-d H:i:s.uP')]);
            $this->budgets->nextSeq($budgetId);

            return $this->reload($budgetId);
        });
    }

    /**
     * Deletes the budget for everyone: every child is tombstoned and each
     * member's user feed is told. The transactions stay in their wallets.
     * Deleting a tombstone returns it, for the person who owned it.
     *
     * @param  Closure(SharedBudgetRecord): array<string, mixed>  $present
     */
    public function delete(string $actorId, string $budgetId, int $version, Closure $present): SharedBudgetRecord
    {
        return DB::transaction(function () use ($actorId, $budgetId, $version, $present): SharedBudgetRecord {
            $current = $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;

            if ($current->isTombstoned()) {
                return $current->ownerId === $actorId ? $current : throw new SharedBudgetNotFound;
            }

            $this->authorizer->owner($actorId, $budgetId);
            $this->refuseStale($current, $version, $present);
            $this->tombstoneEverything($budgetId);

            return $this->reload($budgetId);
        });
    }

    /**
     * Creates a line (`$version` 0) or changes its limit. Owner only. A
     * category's list stays free of duplicates among live lines.
     *
     * @param  array{category_id: string, limit_minor: ?int}  $values
     * @param  Closure(SharedBudgetLineRecord): array<string, mixed>  $present
     */
    public function saveLine(string $actorId, string $budgetId, string $lineId, array $values, int $version, Closure $present): SavedSharedBudgetLine
    {
        $stored = $this->budgets->findLine($lineId);

        if ($stored !== null && $stored->sharedBudgetId !== $budgetId) {
            throw new SharedBudgetNotFound;
        }

        if ($stored === null && ($version > 0 || $this->budgets->identifierTaken($lineId) || $this->identifiers->isTaken($lineId))) {
            throw new SharedBudgetNotFound;
        }

        return DB::transaction(function () use ($actorId, $budgetId, $lineId, $values, $version, $present, $stored): SavedSharedBudgetLine {
            if ($stored === null) {
                try {
                    $this->categories->lockEligible($actorId, $values['category_id']);
                } catch (CategoryNotEligible) {
                    $this->authorizer->owner($actorId, $budgetId);

                    throw new SharedBudgetStateInvalid([new FieldError('/category_id', ErrorCode::InvalidEnumValue)]);
                }
            }

            $budget = $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;
            $this->authorizer->owner($actorId, $budgetId);

            if ($stored === null) {
                $this->insertLine($budget, $lineId, $values);

                return new SavedSharedBudgetLine($this->reloadLine($lineId), true);
            }

            $line = $this->reloadLine($lineId);
            $this->refuseLineTombstone($line, $present);

            if ($line->categoryId === $values['category_id'] && $line->limitMinor === $values['limit_minor']) {
                return new SavedSharedBudgetLine($line, false);
            }

            if ($version !== $line->version) {
                throw new SharedBudgetVersionConflict($present($line));
            }

            SharedBudgetRules::assertCategoryUnchanged($line, $values['category_id']);

            $this->budgets->updateLine($lineId, ['limit_minor' => $values['limit_minor']], $this->budgets->nextSeq($budgetId));
            $this->recordLimitFromNow($budget, $lineId, $values['limit_minor']);
            $this->budgets->nextSeq($budgetId);

            return new SavedSharedBudgetLine($this->reloadLine($lineId), false);
        });
    }

    /**
     * Removes a line from the list. Past entries keep their category, and
     * past periods keep the line's limit. Deleting a tombstone returns it.
     *
     * @param  Closure(SharedBudgetLineRecord): array<string, mixed>  $present
     */
    public function deleteLine(string $actorId, string $budgetId, string $lineId, int $version, Closure $present): SharedBudgetLineRecord
    {
        return DB::transaction(function () use ($actorId, $budgetId, $lineId, $version, $present): SharedBudgetLineRecord {
            $budget = $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;
            $this->authorizer->owner($actorId, $budgetId);

            $line = $this->budgets->findLine($lineId);

            if ($line === null || $line->sharedBudgetId !== $budgetId) {
                throw new SharedBudgetNotFound;
            }

            if ($line->isTombstoned()) {
                return $line;
            }

            if ($version !== $line->version) {
                throw new SharedBudgetVersionConflict($present($line));
            }

            $this->budgets->updateLine($lineId, ['deleted_at' => $this->budgets->now()->format('Y-m-d H:i:s.uP')], $this->budgets->nextSeq($budgetId));
            $this->recordLimitFromNow($budget, $lineId, null);
            $this->budgets->nextSeq($budgetId);

            return $this->reloadLine($lineId);
        });
    }

    /**
     * Adds a person who accepted an invitation. Sharing calls it inside the
     * unit of work that closes the invitation; the budget row is locked by then.
     */
    public function addMember(string $budgetId, string $userId): SharedBudgetMemberRecord
    {
        return $this->budgets->activeMember($budgetId, $userId)
            ?? $this->budgets->insertMember($budgetId, $userId, SharedBudgetRole::Member, $this->budgets->nextSeq($budgetId), $this->userCounter->next($userId));
    }

    /**
     * Removes a member. Owner only; the owner transfers or deletes instead.
     *
     * @throws SharedBudgetActionForbidden
     */
    public function removeMember(string $actorId, string $budgetId, string $memberUserId): void
    {
        DB::transaction(function () use ($actorId, $budgetId, $memberUserId): void {
            $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;
            $this->authorizer->owner($actorId, $budgetId);

            $member = $this->budgets->activeMember($budgetId, $memberUserId) ?? throw new SharedBudgetNotFound;

            if ($member->isOwner()) {
                throw new SharedBudgetActionForbidden;
            }

            $this->depart($member);
        });
    }

    /**
     * The caller leaves. The owner transfers or deletes first (ADR-0027 rule 3).
     *
     * @throws SharedBudgetActionForbidden
     */
    public function leave(string $actorId, string $budgetId): void
    {
        DB::transaction(function () use ($actorId, $budgetId): void {
            $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;
            $member = $this->authorizer->member($actorId, $budgetId);

            if ($member->isOwner()) {
                throw new SharedBudgetActionForbidden;
            }

            $this->depart($member);
        });
    }

    /**
     * Hands the budget to another active member; the old owner stays a member.
     *
     * @param  Closure(SharedBudgetRecord): array<string, mixed>  $present
     */
    public function transferOwnership(string $actorId, string $budgetId, string $newOwnerId, int $version, Closure $present): SharedBudgetRecord
    {
        return DB::transaction(function () use ($actorId, $budgetId, $newOwnerId, $version, $present): SharedBudgetRecord {
            $current = $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;
            $owner = $this->authorizer->owner($actorId, $budgetId);
            $this->refuseStale($current, $version, $present);

            $successor = $this->budgets->activeMember($budgetId, $newOwnerId);

            if ($successor === null || $successor->isOwner()) {
                throw new SharedBudgetStateInvalid([new FieldError('/user_id', ErrorCode::InvalidEnumValue)]);
            }

            $this->handOver($budgetId, $owner, $successor);

            return $this->reload($budgetId);
        });
    }

    /**
     * The plan demotion (ADR-0027 rule 14): when the owner's plan allows fewer
     * members than a budget has, every member ranked beyond the quota (owner
     * first, then joining order) becomes read-only. Nobody is removed and
     * nobody is made writable again. Idempotent: a second run finds none.
     *
     * @param  ?int  $quota  null = unlimited
     * @return list<string> the people made read-only
     */
    public function limitMembersTo(string $budgetId, ?int $quota): array
    {
        if ($quota === null) {
            return [];
        }

        return DB::transaction(function () use ($budgetId, $quota): array {
            if ($this->budgets->findForUpdate($budgetId) === null) {
                return [];
            }

            $demoted = array_values(array_filter(
                array_slice($this->budgets->activeMembers($budgetId), max(0, $quota)),
                fn (SharedBudgetMemberRecord $member): bool => ! $member->readOnly,
            ));

            usort($demoted, fn (SharedBudgetMemberRecord $a, SharedBudgetMemberRecord $b): int => strcmp($a->userId, $b->userId));

            foreach ($demoted as $member) {
                $this->budgets->updateMember($member->id, ['read_only' => true], $this->budgets->nextSeq($budgetId), $this->userCounter->next($member->userId));
            }

            if ($demoted !== []) {
                $this->budgets->nextSeq($budgetId);
            }

            return array_map(fn (SharedBudgetMemberRecord $member): string => $member->userId, $demoted);
        });
    }

    /**
     * Carries a category's new display fields to every live line naming it.
     * The caller holds the category's lock (ADR-0027 rule 10).
     */
    public function republishCategory(string $categoryId, string $name, ?string $icon, ?string $color): void
    {
        foreach ($this->budgets->budgetIdsWithLiveLineFor($categoryId) as $budgetId) {
            $this->budgets->findForUpdate($budgetId);

            foreach ($this->budgets->liveLines($budgetId) as $line) {
                if ($line->categoryId === $categoryId) {
                    $this->budgets->updateLine($line->id, ['name' => $name, 'icon' => $icon, 'color' => $color], $this->budgets->nextSeq($budgetId));
                }
            }

            $this->budgets->nextSeq($budgetId);
        }
    }

    /**
     * An account purge (ADR-0027 rule 15): each owned budget passes to its
     * earliest active member, or is deleted when no other member remains,
     * and the person leaves the rest. Each budget is its own unit of work.
     * Idempotent: with no membership left it does nothing.
     *
     * @return int memberships ended
     */
    public function removeDepartedUser(string $userId): int
    {
        $ended = 0;

        foreach ($this->budgets->liveBudgetIdsOf($userId) as $budgetId) {
            DB::transaction(function () use ($userId, $budgetId, &$ended): void {
                $this->budgets->findForUpdate($budgetId);
                $member = $this->budgets->activeMember($budgetId, $userId);

                if ($member === null) {
                    return;
                }

                $ended++;

                if ($member->isOwner()) {
                    $others = array_values(array_filter($this->budgets->activeMembers($budgetId), fn (SharedBudgetMemberRecord $other): bool => $other->userId !== $userId));

                    if ($others === []) {
                        $this->tombstoneEverything($budgetId);

                        return;
                    }

                    $this->handOver($budgetId, $member, $others[0]);
                    $member = $this->budgets->activeMember($budgetId, $userId) ?? $member;
                }

                $this->depart($member);
            });
        }

        return $ended;
    }

    /**
     * @param  array{name: string, currency: string, start_day: int, limit_minor: ?int}  $values
     */
    private function create(string $actorId, string $budgetId, array $values): SharedBudgetRecord
    {
        return DB::transaction(function () use ($actorId, $budgetId, $values): SharedBudgetRecord {
            $this->budgets->insert($budgetId, $actorId, $this->profiles->timezoneOf($actorId), $values);
            $budget = $this->budgets->findForUpdate($budgetId) ?? throw new SharedBudgetNotFound;

            $this->budgets->insertMember($budgetId, $actorId, SharedBudgetRole::Owner, $this->budgets->nextSeq($budgetId), $this->userCounter->next($actorId));
            $this->budgets->recordStartDay($budgetId, $values['start_day'], null, $this->budgets->nextSeq($budgetId));
            $this->recordLimitFromNow($budget, null, $values['limit_minor']);
            $this->budgets->nextSeq($budgetId);

            return $this->reload($budgetId);
        });
    }

    /**
     * The shared path of save and patch.
     *
     * @param  array{name: string, currency: string, start_day: int, limit_minor: ?int}  $values
     * @param  Closure(SharedBudgetRecord): array<string, mixed>  $present
     */
    private function change(string $actorId, SharedBudgetRecord $stored, array $values, int $version, Closure $present): SharedBudgetRecord
    {
        return DB::transaction(function () use ($actorId, $stored, $values, $version, $present): SharedBudgetRecord {
            $current = $this->budgets->findForUpdate($stored->id) ?? throw new SharedBudgetNotFound;

            if ($current->isTombstoned()) {
                if ($current->ownerId !== $actorId) {
                    throw new SharedBudgetNotFound;
                }

                throw new SharedBudgetVersionConflict($present($current));
            }

            $this->authorizer->owner($actorId, $current->id);

            if ($values === $current->writableValues()) {
                return $current;
            }

            $this->refuseStale($current, $version, $present);
            SharedBudgetRules::assertCurrencyUnchanged($current, $values);

            if ($values['limit_minor'] !== $current->limitMinor) {
                $this->recordLimitFromNow($current, null, $values['limit_minor']);
            }

            if ($values['start_day'] !== $current->startDay) {
                $this->budgets->recordStartDay($current->id, $values['start_day'], $this->periods->nextPeriodStart($current), $this->budgets->nextSeq($current->id));
            }

            $this->budgets->update($current->id, [
                'name' => $values['name'],
                'start_day' => $values['start_day'],
                'limit_minor' => $values['limit_minor'],
            ]);
            $this->budgets->nextSeq($current->id);

            return $this->reload($current->id);
        });
    }

    /**
     * @param  array{category_id: string, limit_minor: ?int}  $values
     */
    private function insertLine(SharedBudgetRecord $budget, string $lineId, array $values): void
    {
        foreach ($this->budgets->liveLines($budget->id) as $line) {
            if ($line->categoryId === $values['category_id']) {
                throw new SharedBudgetStateInvalid([new FieldError('/category_id', ErrorCode::InvalidEnumValue)]);
            }
        }

        $display = $this->categories->displayOf($values['category_id']);

        $this->budgets->insertLine($lineId, $budget->id, [
            'category_id' => $values['category_id'],
            'limit_minor' => $values['limit_minor'],
            'name' => $display['name'],
            'icon' => $display['icon'],
            'color' => $display['color'],
        ], $this->budgets->nextSeq($budget->id));

        $this->recordLimitFromNow($budget, $lineId, $values['limit_minor']);
        $this->budgets->nextSeq($budget->id);
    }

    /**
     * A change applies from the current Budget Period (ADR-0027 rule 12).
     */
    private function recordLimitFromNow(SharedBudgetRecord $budget, ?string $lineId, ?int $limitMinor): void
    {
        $period = $this->periods->containing($budget, $this->periods->today($budget));

        $this->budgets->recordLimit($budget->id, $lineId, $limitMinor, $period->start, $this->budgets->nextSeq($budget->id));
    }

    /**
     * Ends a membership (ADR-0027 rule 8): the person's entries stop following
     * their transactions and lose their notes, and each carries a new stamp so
     * the erasure reaches the other members. No ledger row is touched.
     */
    private function depart(SharedBudgetMemberRecord $member): void
    {
        $now = $this->budgets->now()->format('Y-m-d H:i:s.uP');

        foreach ($this->budgets->followingEntriesOf($member->sharedBudgetId, $member->userId) as $entry) {
            $this->budgets->updateEntry($entry->id, ['detached_at' => $entry->detachedAt?->format('Y-m-d H:i:s.uP') ?? $now, 'description' => null], $this->budgets->nextSeq($member->sharedBudgetId));
        }

        $this->budgets->updateMember($member->id, ['deleted_at' => $now], $this->budgets->nextSeq($member->sharedBudgetId), $this->userCounter->next($member->userId));
        $this->budgets->nextSeq($member->sharedBudgetId);
    }

    private function handOver(string $budgetId, SharedBudgetMemberRecord $owner, SharedBudgetMemberRecord $successor): void
    {
        $people = [$owner, $successor];
        usort($people, fn (SharedBudgetMemberRecord $a, SharedBudgetMemberRecord $b): int => strcmp($a->userId, $b->userId));

        foreach ($people as $person) {
            $this->budgets->updateMember(
                $person->id,
                $person->id === $owner->id ? ['role' => SharedBudgetRole::Member->value] : ['role' => SharedBudgetRole::Owner->value, 'read_only' => false],
                $this->budgets->nextSeq($budgetId),
                $this->userCounter->next($person->userId),
            );
        }

        $this->budgets->update($budgetId, ['owner_id' => $successor->userId]);
        $this->budgets->nextSeq($budgetId);
    }

    private function tombstoneEverything(string $budgetId): void
    {
        $now = $this->budgets->now()->format('Y-m-d H:i:s.uP');

        foreach ($this->budgets->liveEntries($budgetId) as $entry) {
            $this->budgets->updateEntry($entry->id, ['deleted_at' => $now], $this->budgets->nextSeq($budgetId));
        }

        foreach ($this->budgets->liveLines($budgetId) as $line) {
            $this->budgets->updateLine($line->id, ['deleted_at' => $now], $this->budgets->nextSeq($budgetId));
        }

        $members = $this->budgets->activeMembers($budgetId);
        usort($members, fn (SharedBudgetMemberRecord $a, SharedBudgetMemberRecord $b): int => strcmp($a->userId, $b->userId));

        foreach ($members as $member) {
            $this->budgets->updateMember($member->id, ['deleted_at' => $now], $this->budgets->nextSeq($budgetId), $this->userCounter->next($member->userId));
        }

        $this->budgets->update($budgetId, ['deleted_at' => $now]);
        $this->budgets->nextSeq($budgetId);
    }

    /**
     * @param  Closure(SharedBudgetRecord): array<string, mixed>  $present
     */
    private function refuseStale(SharedBudgetRecord $current, int $version, Closure $present): void
    {
        if ($version !== $current->version) {
            throw new SharedBudgetVersionConflict($present($current));
        }
    }

    /**
     * @param  Closure(SharedBudgetLineRecord): array<string, mixed>  $present
     */
    private function refuseLineTombstone(SharedBudgetLineRecord $line, Closure $present): void
    {
        if ($line->isTombstoned()) {
            throw new SharedBudgetVersionConflict($present($line));
        }
    }

    private function reload(string $budgetId): SharedBudgetRecord
    {
        return $this->budgets->find($budgetId) ?? throw new SharedBudgetNotFound;
    }

    private function reloadLine(string $lineId): SharedBudgetLineRecord
    {
        return $this->budgets->findLine($lineId) ?? throw new SharedBudgetNotFound;
    }
}
