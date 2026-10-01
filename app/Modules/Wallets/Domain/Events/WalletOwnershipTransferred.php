<?php

namespace App\Modules\Wallets\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The owner handed ownership to another active member and became an editor.
 * Dispatched after commit, identifiers and scalars only (P-11).
 */
final readonly class WalletOwnershipTransferred implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $walletId,
        public string $previousOwnerId,
        public string $newOwnerId,
    ) {}
}
