<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;

/**
 * Reads wallets for a caller, each with their role and whether the currency
 * is locked. One indexed join per page, no aggregation (SC-006): the balance
 * is the cached column.
 */
final class WalletQueryService
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly WalletAuthorizer $authorizer,
        private readonly PostingTotals $postings,
    ) {}

    /**
     * The caller's live wallets, archived included.
     *
     * @param  'name'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<WalletView>, next: array{value: string, id: string}|null}
     */
    public function listFor(string $userId, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $rows = $this->wallets->listForMember($userId, $sort, $descending, $after, $limit);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)]['wallet'];
            $next = ['value' => $this->wallets->sortValue($last, $sort), 'id' => $last->id];
        }

        $locked = array_flip($this->postings->lockedAmong(array_map(fn (array $row): string => $row['wallet']->id, $page)));

        return [
            'items' => array_map(fn (array $row): WalletView => new WalletView($row['wallet'], $row['role'], isset($locked[$row['wallet']->id])), $page),
            'next' => $next,
        ];
    }

    /**
     * One wallet: live for its members; a tombstone for those who were
     * members when it was deleted; not-found for anyone else.
     *
     * @throws WalletNotFound
     */
    public function find(string $userId, string $walletId): WalletView
    {
        $role = $this->authorizer->authorize($userId, $walletId, Permission::W01ReadWallet);
        $wallet = $this->wallets->find($walletId) ?? throw new WalletNotFound;

        return new WalletView($wallet, $role, $this->postings->hasAny($walletId));
    }

    /**
     * The caller's role in a wallet they may read, or not-found.
     *
     * @throws WalletNotFound
     */
    public function assertCanRead(string $userId, string $walletId): void
    {
        $this->authorizer->authorize($userId, $walletId, Permission::W01ReadWallet);
    }
}
