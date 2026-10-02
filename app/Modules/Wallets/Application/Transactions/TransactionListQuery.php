<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionNotFound;

/**
 * Reads a wallet's transactions for any active member (row T-01). A non-member,
 * and everyone once the wallet is deleted, gets not-found.
 */
final class TransactionListQuery
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly WalletRepository $wallets,
        private readonly WalletAuthorizer $authorizer,
    ) {}

    /**
     * The wallet's live transactions: its own and the transfers into it.
     *
     * @param  array<string, list<array{op: string, value: string}>>  $filters
     * @param  'date'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<TransactionView>, next: array{value: string, id: string}|null}
     */
    public function list(string $userId, string $walletId, array $filters, ?string $search, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $this->assertReadable($userId, $walletId);

        $rows = $this->transactions->listLive($walletId, $filters, $search, $sort, $descending, $after, $limit);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $this->transactions->sortValue($last, $sort), 'id' => $last->id];
        }

        return [
            'items' => array_map(fn ($transaction): TransactionView => new TransactionView($transaction), $page),
            'next' => $next,
        ];
    }

    /**
     * One transaction of the wallet's list, live or tombstoned; a transfer
     * is found under either of its wallets.
     *
     * @throws TransactionNotFound
     */
    public function find(string $userId, string $walletId, string $transactionId): TransactionView
    {
        $this->assertReadable($userId, $walletId);

        $transaction = $this->transactions->find($transactionId);

        if ($transaction === null || ! $transaction->touches($walletId)) {
            throw new TransactionNotFound;
        }

        return new TransactionView($transaction);
    }

    private function assertReadable(string $userId, string $walletId): void
    {
        $this->authorizer->authorize($userId, $walletId, Permission::T01ReadTransactions);

        if ($this->wallets->find($walletId)?->isTombstone() ?? true) {
            throw new TransactionNotFound;
        }
    }
}
