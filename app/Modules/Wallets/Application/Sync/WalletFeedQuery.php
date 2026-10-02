<?php

namespace App\Modules\Wallets\Application\Sync;

use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Dto\MemberView;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\SyncFeedReader;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;

/**
 * The wallet scope's side of GET /sync/wallets/{id}/changes (spec 013, R-8,
 * R-12): the wallet, its members, its category references, its transactions
 * and the transfers into it, each rendered as its read renders it. The
 * reader needs row W-01, including spec 010's rule for a deleted wallet;
 * anyone else, and a purged wallet, is not-found.
 */
final class WalletFeedQuery
{
    public function __construct(
        private readonly WalletAuthorizer $authorizer,
        private readonly WalletRepository $wallets,
        private readonly SyncFeedReader $feed,
        private readonly PostingTotals $postings,
        private readonly DisplayNames $displayNames,
        private readonly FeedPresenter $presenter,
    ) {}

    /**
     * The wallet scope's counter and purge horizon, once the reader may read it.
     *
     * @return array{counter: int, purged: int}
     *
     * @throws WalletNotFound
     */
    public function scope(string $readerId, string $walletId): array
    {
        $this->authorizer->authorize($readerId, $walletId, Permission::W01ReadWallet);

        return $this->feed->walletScope($walletId) ?? throw new WalletNotFound;
    }

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     */
    public function page(string $readerId, string $walletId, int $after, int $limit): array
    {
        $role = $this->authorizer->authorize($readerId, $walletId, Permission::W01ReadWallet);
        $rows = [];

        $wallet = $this->wallets->find($walletId);
        if ($wallet !== null && $wallet->changeSeq > $after) {
            $rows[] = new FeedRecord('wallet', $wallet->changeSeq, $this->presenter->wallet(new WalletView($wallet, $role, $this->postings->hasAny($walletId))));
        }

        $members = $this->feed->membersChangedIn($walletId, $after, $limit + 1);
        $names = $this->displayNames->of(array_values(array_unique(array_map(fn ($member): string => $member->userId, $members))));
        foreach ($members as $member) {
            $rows[] = new FeedRecord('wallet_member', $member->changeSeq, $this->presenter->member(new MemberView($member, $names[$member->userId] ?? null)));
        }

        foreach ($this->feed->referencesChangedIn($walletId, $after, $limit + 1) as $reference) {
            $rows[] = new FeedRecord('category_reference', $reference->changeSeq, $this->presenter->categoryReference($reference));
        }

        foreach ($this->feed->transactionsChangedIn($walletId, $after, $limit + 1) as $entry) {
            $rows[] = new FeedRecord('transaction', $entry['position'], $this->presenter->transaction(new TransactionView($entry['transaction'])));
        }

        usort($rows, fn (FeedRecord $a, FeedRecord $b): int => $a->position <=> $b->position);

        return array_slice($rows, 0, $limit + 1);
    }
}
