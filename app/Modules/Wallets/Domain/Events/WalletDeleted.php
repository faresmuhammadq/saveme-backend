<?php

namespace App\Modules\Wallets\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A wallet was deleted (tombstoned) by its owner. Records of other modules in the wallet are tombstoned inside the deletion's unit of work, not by a listener of this event (spec 010, FR-010).
 * Dispatched after commit, identifiers and scalars only (P-11).
 */
final readonly class WalletDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $walletId,
        public string $actorUserId,
    ) {}
}
