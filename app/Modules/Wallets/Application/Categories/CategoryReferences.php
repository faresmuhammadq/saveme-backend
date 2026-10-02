<?php

namespace App\Modules\Wallets\Application\Categories;

use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryReferenceRepository;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use DateTimeZone;
use LogicException;

/**
 * Publishes, republishes and withdraws Category References (spec 011, R-8).
 *
 * LOCK ORDER (spec FR-023, ADR-0014 rule 10), the rule every caller follows:
 * category rows (share or update) first, then wallet rows, several wallets in
 * ascending id. B6 and B7 take the category in share mode (see
 * CategoryRepository::findForShare) before they lock the wallet and call
 * publish(); never lock a wallet and then a category.
 *
 * Every method must run inside the caller's transaction. Each wallet touched
 * is locked, the reference takes the wallet's next change_seq, and the wallet
 * takes one more as its final stamp (the highest in the scope, spec 010 R-3);
 * the wallet's own version does not move.
 */
final class CategoryReferences
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly CategoryReferenceRepository $references,
        private readonly WalletRepository $wallets,
        private readonly ScopeSequence $sequence,
        private readonly WalletAuthorizer $authorizer,
    ) {}

    /**
     * Makes sure the wallet holds a live reference to the person's category.
     * A no-op for a platform category (every member already holds the set),
     * for an unknown id, and when a live reference exists.
     */
    public function publish(string $walletId, string $categoryId): void
    {
        $category = $this->categories->findAny($categoryId);

        if ($category === null) {
            return;
        }

        if ($category->isTombstone()) {
            throw new LogicException('A reference to a deleted category can not be published.');
        }

        $this->lockWallet($walletId);

        if ($this->references->liveFor($walletId, $categoryId) !== null) {
            return;
        }

        $existing = $this->references->anyFor($walletId, $categoryId);

        if ($existing !== null) {
            $this->references->refresh($existing->id, $category, $this->sequence->nextFor($walletId));
        } else {
            $this->references->insert($walletId, $category, $this->sequence->nextFor($walletId));
        }

        $this->sequence->nextFor($walletId);
    }

    /**
     * Tombstones the wallet's reference once the caller's last record in the
     * wallet stops using the category (B6 and B7 call it).
     */
    public function withdrawIfUnused(string $walletId, string $categoryId): void
    {
        $this->lockWallet($walletId);

        $live = $this->references->liveFor($walletId, $categoryId);

        if ($live === null) {
            return;
        }

        $this->references->tombstone($live->id, now()->toDateTimeImmutable(), $this->sequence->nextFor($walletId));
        $this->sequence->nextFor($walletId);
    }

    /**
     * Brings every live reference up to the category's display fields. The
     * category row is already locked by the caller.
     */
    public function republish(CategoryRecord $category): void
    {
        foreach ($this->walletIdsInLockOrder($category->id) as $walletId) {
            $this->lockWallet($walletId);

            $live = $this->references->liveFor($walletId, $category->id);

            if ($live === null) {
                continue;
            }

            $this->references->refresh($live->id, $category, $this->sequence->nextFor($walletId));
            $this->sequence->nextFor($walletId);
        }
    }

    /**
     * Tombstones every live reference to a deleted category.
     */
    public function withdrawAll(string $categoryId): void
    {
        $at = now()->toDateTimeImmutable();

        foreach ($this->walletIdsInLockOrder($categoryId) as $walletId) {
            $this->lockWallet($walletId);

            $live = $this->references->liveFor($walletId, $categoryId);

            if ($live === null) {
                continue;
            }

            $this->references->tombstone($live->id, $at, $this->sequence->nextFor($walletId));
            $this->sequence->nextFor($walletId);
        }
    }

    /**
     * The wallet's live references in publication order, for any active
     * member (permission matrix row T-01). Anyone else, and everyone once the
     * wallet is deleted, gets not-found.
     *
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<CategoryReferenceRecord>, next: array{value: string, id: string}|null}
     *
     * @throws WalletNotFound
     */
    public function listFor(string $userId, string $walletId, ?array $after, int $limit): array
    {
        $this->authorizer->authorize($userId, $walletId, Permission::T01ReadTransactions);

        if ($this->wallets->find($walletId)?->isTombstone() ?? true) {
            throw new WalletNotFound;
        }

        $rows = $this->references->listLive($walletId, $after, $limit);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $last->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), 'id' => $last->id];
        }

        return ['items' => $page, 'next' => $next];
    }

    /**
     * @return list<string> the wallets holding a live reference, ascending
     */
    private function walletIdsInLockOrder(string $categoryId): array
    {
        $ids = $this->references->liveWalletIdsFor($categoryId);
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function lockWallet(string $walletId): void
    {
        $this->wallets->findForUpdate($walletId) ?? throw new WalletNotFound;
    }
}
