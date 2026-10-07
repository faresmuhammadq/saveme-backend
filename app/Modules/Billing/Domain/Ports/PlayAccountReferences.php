<?php

namespace App\Modules\Billing\Domain\Ports;

/**
 * The Play Account Reference of each account (spec 024, FR-005): a keyed hash
 * of the account id, created on first need and resolvable back to the account.
 */
interface PlayAccountReferences
{
    /**
     * The account's reference, created if it has none.
     */
    public function for(string $userId): string;

    public function userFor(string $reference): ?string;

    /**
     * Deletes the account's reference (the account purge).
     */
    public function delete(string $userId): int;
}
