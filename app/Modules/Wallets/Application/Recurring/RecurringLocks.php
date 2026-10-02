<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Application\Transactions\DriftedUnderLock;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The write algorithm of every recurring write (spec 014, plan design note 1,
 * FR-026, ADR-0014 rule 10), in one unit of work of the database:
 *
 * 1. read the rule and occurrence unlocked, to learn what to lock;
 * 2. share-lock the categories involved, ascending id (the stored and the
 *    requested, the occurrence's, and the due occurrences' when asked);
 * 3. lock the wallets, ascending id (the rule's wallet and a transfer's
 *    destination, a requested one only when the actor is a member of it);
 * 4. lock the rule row, then the occurrence row;
 * 5. read again, and begin from the top (at most three times) when a
 *    concurrent write moved what the locks depend on.
 *
 * Never a category after a wallet, nor a wallet after the rule. The caller's
 * body then locks the rule's other occurrences, ascending id, when it
 * touches them.
 */
final class RecurringLocks
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly RecurringRuleRepository $rules,
        private readonly OccurrenceRepository $occurrences,
        private readonly WalletRepository $wallets,
        private readonly MembershipRepository $members,
        private readonly CategoryRepository $categories,
    ) {}

    /**
     * @template T
     *
     * @param  ?string  $actorId  whose membership decides whether a requested destination is locked; null for the job
     * @param  list<?string>  $requestedCategoryIds
     * @param  Closure(?RecurringRuleRecord): list<string>|null  $dueCategories  categories of the occurrences the body will confirm, probed before locking from the rule as read, and again from the rule as locked
     * @param  ?RecurringRuleRecord  $known  the rule as the caller just read it, sparing one read
     * @param  Closure(?RecurringRuleRecord, ?OccurrenceRecord, array<string, ?WalletRecord>): T  $body
     * @param  Closure(): T  $exhausted  what to answer when the locks never settle
     * @return T
     */
    public function run(?string $actorId, string $walletId, string $ruleId, ?string $occurrenceId, array $requestedCategoryIds, ?string $requestedCounterId, ?Closure $dueCategories, Closure $body, Closure $exhausted, ?RecurringRuleRecord $known = null): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($actorId, $walletId, $ruleId, $occurrenceId, $requestedCategoryIds, $requestedCounterId, $dueCategories, $body, $known) {
                    $seenRule = $known ?? $this->rules->find($ruleId);
                    $seenOccurrence = $occurrenceId === null ? null : $this->occurrences->find($occurrenceId);
                    $ownRule = $seenRule !== null && $seenRule->walletId === $walletId ? $seenRule : null;

                    $categoryIds = self::ascending([
                        $ownRule?->categoryId,
                        $ownRule?->payoutCategoryId,
                        $seenOccurrence?->categoryId,
                        ...$requestedCategoryIds,
                        ...($dueCategories === null ? [] : $dueCategories($ownRule)),
                    ]);

                    foreach ($categoryIds as $categoryId) {
                        $this->categories->findForShare($categoryId) ?? $this->categories->findPlatformForShare($categoryId);
                    }

                    $walletIds = [$walletId, $ownRule?->counterWalletId];

                    if ($requestedCounterId !== null && $actorId !== null && $this->members->activeFor($requestedCounterId, $actorId) !== null) {
                        $walletIds[] = $requestedCounterId;
                    }

                    $wallets = [];
                    foreach (self::ascending($walletIds) as $id) {
                        $wallets[$id] = $this->wallets->findForUpdate($id);
                    }

                    $rule = $this->rules->findForUpdate($ruleId);
                    $occurrence = $occurrenceId === null ? null : $this->occurrences->findForUpdate($occurrenceId);

                    $locked = $rule !== null && $rule->walletId === $walletId ? $rule : null;
                    $lockedCategories = array_flip($categoryIds);
                    $due = $dueCategories === null ? [] : $dueCategories($locked);

                    if (
                        self::signature($locked, $occurrence) !== self::signature($ownRule, $seenOccurrence)
                        || array_filter($due, fn (string $id): bool => ! isset($lockedCategories[$id])) !== []
                    ) {
                        throw new DriftedUnderLock;
                    }

                    return $body($rule, $occurrence, $wallets);
                });
            } catch (DriftedUnderLock) {
                // A concurrent write landed between the read and the locks: go again.
            }
        }

        return $exhausted();
    }

    /**
     * What the locks depend on.
     *
     * @return array{?string, ?string, ?string, bool, ?string, bool}
     */
    private static function signature(?RecurringRuleRecord $rule, ?OccurrenceRecord $occurrence): array
    {
        return [
            $rule?->counterWalletId,
            $rule?->categoryId,
            $rule?->payoutCategoryId,
            $rule === null || $rule->isTombstone(),
            $occurrence?->categoryId,
            $occurrence === null || $occurrence->isTombstone(),
        ];
    }

    /**
     * @param  list<?string>  $ids
     * @return list<string>
     */
    private static function ascending(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (?string $id): bool => $id !== null)));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
