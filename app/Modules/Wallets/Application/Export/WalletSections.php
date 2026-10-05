<?php

namespace App\Modules\Wallets\Application\Export;

use App\Modules\Identity\Application\Export\ExportSection;
use App\Modules\Identity\Application\Export\ExportTarget;
use App\Modules\Identity\Application\Export\ExportWriter;
use App\Modules\Identity\Application\Export\WalletUnavailable;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryReferenceRepository;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Membership\MemberRecord;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Ports\SyncFeedReader;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Transactions\TransactionRecord;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Wallets' share of an export (spec 022, FR-002 to FR-007, R-3, R-4): the
 * wallets the person actively belongs to with everything a member can read
 * in them — transactions, recurring rules, occurrences — their own
 * categories and memberships, and the spreadsheet of transactions.
 *
 * Reads through the Wallets repositories and the sync feed reader, as the
 * person: only wallets where they hold an active membership, tombstones still
 * within retention as tombstones. Authorship is `me` or `other_member`,
 * never an identifier; a category on someone else's entry is the label the
 * person already sees through the wallet's category references. Postings,
 * counters and `auto_confirm_from` never leave. A wallet is read one page at
 * a time, so memory stays flat.
 */
final class WalletSections implements ExportSection
{
    private const PAGE = 500;

    /** @var array<string, string|null> wallet id => name when the person may see it */
    private array $visibleNames = [];

    public function __construct(
        private readonly MembershipRepository $members,
        private readonly WalletRepository $wallets,
        private readonly SyncFeedReader $feed,
        private readonly CategoryReferenceRepository $references,
        private readonly CategoryRepository $categories,
        private readonly ProfileRepository $profiles,
    ) {}

    public function write(ExportTarget $target, ExportWriter $writer): void
    {
        $userId = $target->userId;
        $this->visibleNames = [];

        foreach (['wallets.json', 'transactions.json', 'recurring_rules.json', 'occurrences.json', 'categories.json'] as $file) {
            $writer->declare($file);
        }

        if ($target->isAccount()) {
            $writer->declare('memberships.json');
        }

        $platform = $this->platformLabels($userId);
        $own = $this->ownLabels($userId);
        $usedCategories = [];
        $written = [];

        foreach ($this->walletIds($target) as $walletId) {
            $wallet = $this->wallets->find($walletId);
            $member = $this->members->activeFor($walletId, $userId);

            if ($wallet === null || $wallet->isTombstone() || $member === null) {
                if (! $target->isAccount()) {
                    throw new WalletUnavailable;
                }

                continue;
            }

            $this->visibleNames[$walletId] = $wallet->name;
            $categoryLabels = $platform + $this->referenceLabels($walletId) + $own;

            $writer->append('wallets.json', $this->walletRecord($wallet, $member, $this->members->countActive($walletId)));
            $this->writeTransactions($target, $writer, $wallet, $categoryLabels, $usedCategories, $written);
            $this->writeRules($userId, $writer, $wallet, $categoryLabels, $usedCategories);
            $this->writeOccurrences($writer, $wallet, $categoryLabels, $usedCategories);
        }

        if ($target->isAccount()) {
            $this->writeOwnCategories($userId, $writer);
            $this->writeMemberships($userId, $writer);
        } else {
            $this->writeUsedCategories($writer, $usedCategories);
        }
    }

    /**
     * The wallets to read, ascending by id.
     *
     * @return list<string>
     */
    private function walletIds(ExportTarget $target): array
    {
        if (! $target->isAccount()) {
            return [(string) $target->walletId];
        }

        $ids = [];
        $after = '';

        do {
            $page = $this->members->liveWalletIdsOf($target->userId, $after, self::PAGE);
            array_push($ids, ...$page);
            $after = $page === [] ? '' : $page[array_key_last($page)];
        } while (count($page) === self::PAGE);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function walletRecord(WalletRecord $wallet, MemberRecord $member, int $memberCount): array
    {
        return [
            'id' => $wallet->id,
            'name' => $wallet->name,
            'type' => $wallet->type->value,
            'currency' => $wallet->currency,
            'opening_balance_minor' => $wallet->openingBalanceMinor,
            'icon' => $wallet->icon,
            'color' => $wallet->color,
            'archived_at' => self::instant($wallet->archivedAt),
            'role' => $member->role->value,
            'member_count' => $memberCount,
            'created_at' => self::instant($wallet->createdAt),
            'updated_at' => self::instant($wallet->updatedAt),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $used
     * @param  array<string, true>  $written  transfers already written, so a transfer between two exported wallets appears once
     */
    private function writeTransactions(ExportTarget $target, ExportWriter $writer, WalletRecord $wallet, array $labels, array &$used, array &$written): void
    {
        $after = 0;

        do {
            $page = $this->feed->transactionsChangedIn($wallet->id, $after, self::PAGE);

            foreach ($page as $entry) {
                $transaction = $entry['transaction'];
                $after = max($after, $entry['position']);

                if ($transaction->counterWalletId !== null) {
                    if (isset($written[$transaction->id]) || ! $this->belongsHere($target, $wallet->id, $transaction)) {
                        continue;
                    }

                    $written[$transaction->id] = true;
                }

                $this->writeTransaction($target->userId, $writer, $wallet, $transaction, $labels, $used);
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * A transfer is written with its source wallet; with the destination's
     * only when the person cannot read the source (or it is not part of this export).
     */
    private function belongsHere(ExportTarget $target, string $feedWalletId, TransactionRecord $transaction): bool
    {
        if ($transaction->walletId === $feedWalletId) {
            return true;
        }

        if (! $target->isAccount()) {
            return true;
        }

        return $this->members->activeFor($transaction->walletId, $target->userId) === null;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $used
     */
    private function writeTransaction(string $userId, ExportWriter $writer, WalletRecord $wallet, TransactionRecord $transaction, array $labels, array &$used): void
    {
        if ($transaction->isTombstone()) {
            $writer->append('transactions.json', ['id' => $transaction->id, 'deleted_at' => self::instant($transaction->deletedAt), 'wallet_id' => $wallet->id]);

            return;
        }

        $category = $this->label($transaction->categoryId, $labels, $used);
        $counter = $this->counterName($userId, $transaction->counterWalletId);
        $author = $transaction->createdBy === $userId ? 'me' : 'other_member';

        $writer->append('transactions.json', [
            'id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'kind' => $transaction->kind->value,
            'date' => $transaction->date,
            'amount_minor' => $transaction->amountMinor,
            'currency' => $transaction->currency,
            'category_id' => $transaction->categoryId,
            'category' => $category,
            'counter_wallet_id' => $transaction->counterWalletId,
            'counter_wallet' => $counter,
            'description' => $transaction->description,
            'memo' => $transaction->memo,
            'counterparty' => $transaction->counterparty,
            'cleared' => $transaction->cleared,
            'author' => $author,
            'recurring_rule_id' => $transaction->recurringRuleId,
            'occurrence_id' => $transaction->occurrenceId,
            'created_at' => self::instant($transaction->createdAt),
            'updated_at' => self::instant($transaction->updatedAt),
        ]);

        $writer->transactionRow([
            'date' => $transaction->date,
            'wallet' => $wallet->name,
            'wallet_id' => $wallet->id,
            'kind' => $transaction->kind->value,
            'amount_minor' => $transaction->amountMinor,
            'currency' => $transaction->currency,
            'category' => $category,
            'counter_wallet' => $counter,
            'description' => $transaction->description,
            'memo' => $transaction->memo,
            'counterparty' => $transaction->counterparty,
            'cleared' => $transaction->cleared,
            'author' => $author,
            'transaction_id' => $transaction->id,
            'created_at' => self::instant($transaction->createdAt),
            'updated_at' => self::instant($transaction->updatedAt),
        ]);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $used
     */
    private function writeRules(string $userId, ExportWriter $writer, WalletRecord $wallet, array $labels, array &$used): void
    {
        $after = 0;

        do {
            $page = $this->feed->rulesChangedIn($wallet->id, $after, self::PAGE);

            foreach ($page as $rule) {
                $after = max($after, $rule->changeSeq);
                $writer->append('recurring_rules.json', $this->ruleRecord($userId, $wallet, $rule, $labels, $used));
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $used
     * @return array<string, mixed>
     */
    private function ruleRecord(string $userId, WalletRecord $wallet, RecurringRuleRecord $rule, array $labels, array &$used): array
    {
        if ($rule->isTombstone()) {
            return ['id' => $rule->id, 'deleted_at' => self::instant($rule->deletedAt), 'wallet_id' => $wallet->id];
        }

        return [
            'id' => $rule->id,
            'wallet_id' => $wallet->id,
            'preset' => $rule->preset->value,
            'status' => $rule->status->value,
            'kind' => $rule->kind->value,
            'amount_minor' => $rule->amountMinor,
            'amount_basis' => $rule->amountBasis->value,
            'currency' => $rule->currency,
            'category_id' => $rule->categoryId,
            'category' => $this->label($rule->categoryId, $labels, $used),
            'counter_wallet_id' => $rule->counterWalletId,
            'counter_wallet' => $this->counterName($userId, $rule->counterWalletId),
            'description' => $rule->description,
            'memo' => $rule->memo,
            'counterparty' => $rule->counterparty,
            'auto_confirm' => $rule->autoConfirm,
            'frequency' => $rule->frequency->value,
            'interval' => $rule->interval,
            'anchor_day' => $rule->anchorDay,
            'start_date' => $rule->startDate,
            'end_date' => $rule->endDate,
            'occurrence_count' => $rule->occurrenceCount,
            'payout_round' => $rule->payoutRound,
            'payout_category_id' => $rule->payoutCategoryId,
            'payout_category' => $this->label($rule->payoutCategoryId, $labels, $used),
            'timezone' => $rule->timezone,
            'author' => $rule->createdBy === $userId ? 'me' : 'other_member',
            'created_at' => self::instant($rule->createdAt),
            'updated_at' => self::instant($rule->updatedAt),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $used
     */
    private function writeOccurrences(ExportWriter $writer, WalletRecord $wallet, array $labels, array &$used): void
    {
        $after = 0;

        do {
            $page = $this->feed->occurrencesChangedIn($wallet->id, $after, self::PAGE);

            foreach ($page as $occurrence) {
                $after = max($after, $occurrence->changeSeq);
                $writer->append('occurrences.json', $this->occurrenceRecord($wallet, $occurrence, $labels, $used));
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $used
     * @return array<string, mixed>
     */
    private function occurrenceRecord(WalletRecord $wallet, OccurrenceRecord $occurrence, array $labels, array &$used): array
    {
        if ($occurrence->isTombstone()) {
            return ['id' => $occurrence->id, 'deleted_at' => self::instant($occurrence->deletedAt), 'wallet_id' => $wallet->id];
        }

        return [
            'id' => $occurrence->id,
            'wallet_id' => $wallet->id,
            'recurring_rule_id' => $occurrence->recurringRuleId,
            'ordinal' => $occurrence->ordinal,
            'is_payout' => $occurrence->isPayout,
            'scheduled_date' => $occurrence->scheduledDate,
            'date' => $occurrence->date,
            'status' => $occurrence->status->value,
            'kind' => $occurrence->kind->value,
            'amount_minor' => $occurrence->amountMinor,
            'currency' => $occurrence->currency,
            'category_id' => $occurrence->categoryId,
            'category' => $this->label($occurrence->categoryId, $labels, $used),
            'description' => $occurrence->description,
            'memo' => $occurrence->memo,
            'counterparty' => $occurrence->counterparty,
            'edited' => $occurrence->edited,
            'transaction_id' => $occurrence->transactionId,
            'auto_confirm_refusal' => $occurrence->autoConfirmRefusal?->value,
            'created_at' => self::instant($occurrence->createdAt),
            'updated_at' => self::instant($occurrence->updatedAt),
        ];
    }

    private function writeOwnCategories(string $userId, ExportWriter $writer): void
    {
        $after = 0;

        do {
            $page = $this->feed->categoriesChangedFor($userId, $after, self::PAGE);

            foreach ($page as $category) {
                $after = max($after, $category->changeSeq);
                $writer->append('categories.json', $this->categoryRecord($category));
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRecord(CategoryRecord $category): array
    {
        if ($category->isTombstone()) {
            return ['id' => $category->id, 'deleted_at' => self::instant($category->deletedAt)];
        }

        return [
            'id' => $category->id,
            'kind' => $category->kind->value,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
            'archived_at' => self::instant($category->archivedAt),
            'created_at' => self::instant($category->createdAt),
            'updated_at' => self::instant($category->updatedAt),
        ];
    }

    private function writeMemberships(string $userId, ExportWriter $writer): void
    {
        $after = 0;

        do {
            $page = $this->feed->membershipsChangedFor($userId, $after, self::PAGE);

            foreach ($page as $member) {
                $after = max($after, $member->memberChangeSeq);

                $writer->append('memberships.json', $member->deletedAt !== null
                    ? ['id' => $member->id, 'deleted_at' => self::instant($member->deletedAt), 'wallet_id' => $member->walletId]
                    : [
                        'id' => $member->id,
                        'wallet_id' => $member->walletId,
                        'role' => $member->role->value,
                        'joined_at' => self::instant($member->createdAt),
                        'state' => 'active',
                    ]);
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * A wallet export's `categories.json`: only the labels its entries use,
     * by the names the person sees.
     *
     * @param  array<string, string|null>  $used  category id => the label shown
     */
    private function writeUsedCategories(ExportWriter $writer, array $used): void
    {
        foreach ($used as $categoryId => $name) {
            $writer->append('categories.json', ['id' => (string) $categoryId, 'name' => $name]);
        }
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $used
     */
    private function label(?string $categoryId, array $labels, array &$used): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        $used[$categoryId] = $labels[$categoryId] ?? null;

        return $used[$categoryId];
    }

    private function counterName(string $userId, ?string $counterWalletId): ?string
    {
        if ($counterWalletId === null) {
            return null;
        }

        if (! array_key_exists($counterWalletId, $this->visibleNames)) {
            $wallet = $this->members->activeFor($counterWalletId, $userId) === null ? null : $this->wallets->find($counterWalletId);
            $this->visibleNames[$counterWalletId] = $wallet === null || $wallet->isTombstone() ? null : $wallet->name;
        }

        return $this->visibleNames[$counterWalletId];
    }

    /**
     * Platform categories by the person's language.
     *
     * @return array<string, string>
     */
    private function platformLabels(string $userId): array
    {
        $locale = $this->profiles->localeOf($userId) === 'ar' ? 'ar' : 'en';
        $labels = [];

        foreach ($this->categories->allPlatform() as $category) {
            $labels[$category->id] = $category->names[$locale];
        }

        return $labels;
    }

    /**
     * The names a wallet publishes for categories, other members' included:
     * what the person already sees.
     *
     * @return array<string, string>
     */
    private function referenceLabels(string $walletId): array
    {
        $labels = [];
        $after = null;

        do {
            $page = $this->references->listLive($walletId, $after, self::PAGE);
            $slice = array_slice($page, 0, self::PAGE);

            foreach ($slice as $reference) {
                $labels[$reference->categoryId] = $reference->name;
            }

            $last = $slice === [] ? null : $slice[array_key_last($slice)];
            $after = count($page) > self::PAGE && $last !== null ? ['value' => $last->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), 'id' => $last->id] : null;
        } while ($after !== null);

        return $labels;
    }

    /**
     * The person's own categories by name.
     *
     * @return array<string, string>
     */
    private function ownLabels(string $userId): array
    {
        $labels = [];
        $after = 0;

        do {
            $page = $this->feed->categoriesChangedFor($userId, $after, self::PAGE);

            foreach ($page as $category) {
                $after = max($after, $category->changeSeq);

                $labels[$category->id] = $category->name;
            }
        } while (count($page) === self::PAGE);

        return $labels;
    }

    private static function instant(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
