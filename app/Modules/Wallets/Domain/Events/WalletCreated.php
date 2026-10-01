<?php

namespace App\Modules\Wallets\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A wallet was created, with its creator as sole owner.
 * Dispatched after commit, identifiers and scalars only (P-11).
 */
final readonly class WalletCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $walletId,
        public string $ownerUserId,
        public string $type,
        public string $currency,
    ) {}
}
