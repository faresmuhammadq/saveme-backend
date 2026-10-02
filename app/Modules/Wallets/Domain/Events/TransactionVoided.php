<?php

namespace App\Modules\Wallets\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A transaction stopped counting: it was deleted (tombstoned). No amount and
 * no text (P-15). Dispatched after commit, identifiers and scalars only
 * (P-11). A wallet deletion tombstones its transactions without this event;
 * consumers listen to WalletDeleted for that.
 */
final readonly class TransactionVoided implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $transactionId,
        public string $walletId,
        public ?string $counterWalletId,
        public string $kind,
        public string $date,
        public ?string $categoryId,
        public string $actorUserId,
    ) {}
}
