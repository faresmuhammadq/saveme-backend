<?php

namespace App\Modules\Wallets\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A transaction began to count in its wallets: it was recorded, amended or
 * restored. `change` is `recorded`, `amended` or `restored`; an amendment
 * carries the previous date and category so a budget can re-cut both
 * periods. No amount and no text (P-15). Dispatched after commit,
 * identifiers and scalars only (P-11).
 */
final readonly class TransactionPosted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $transactionId,
        public string $walletId,
        public ?string $counterWalletId,
        public string $kind,
        public string $date,
        public ?string $previousDate,
        public ?string $categoryId,
        public ?string $previousCategoryId,
        public string $actorUserId,
        public string $change,
    ) {}
}
