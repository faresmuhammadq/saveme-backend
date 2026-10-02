<?php

namespace App\Modules\Wallets\Application\Transactions;

use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Domain\Transactions\Ports\PostingRepository;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * A deleted wallet's transactions go with it, in the same unit of work
 * (spec 012, R-9, FR-015): income and expense, and transfers whose other
 * wallet is absent or already deleted, are tombstoned and their postings
 * removed. A transfer to or from a live wallet stays live with its postings:
 * a deletion never writes into a wallet its author may not write (P-08 rule
 * 2). No other wallet is locked and no transaction event is dispatched;
 * consumers listen to WalletDeleted.
 */
final class TransactionsGoWithWallet implements WalletDeletionParticipant
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly PostingRepository $postings,
    ) {}

    public function tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void
    {
        $deletedAt = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');

        foreach ($this->transactions->liveGoingWith($walletId) as $transaction) {
            $changeSeq = $nextChangeSeq();

            $tombstoned = $this->transactions->updateIfVersion(
                $transaction->id,
                $transaction->version,
                ['deleted_at' => $deletedAt],
                $transaction->walletId === $walletId ? $changeSeq : null,
                $transaction->counterWalletId === $walletId ? $changeSeq : null,
            );

            if (! $tombstoned) {
                throw new RuntimeException('A transaction changed under its wallet\'s lock.');
            }

            $this->postings->removeFor($transaction->id);
        }
    }
}
