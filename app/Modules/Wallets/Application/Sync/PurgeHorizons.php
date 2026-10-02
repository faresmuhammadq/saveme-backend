<?php

namespace App\Modules\Wallets\Application\Sync;

use App\Modules\Wallets\Domain\Ports\TombstoneStore;

/**
 * How another module's tombstone purge takes a wallet's lock and raises its
 * purge horizon (spec 016, R-14; ADR-0008 rule 12), exactly as Wallets' own
 * purge does for its records: wallets locked in ascending id, the horizon
 * raised in the same transaction as the removal.
 */
final class PurgeHorizons
{
    public function __construct(private readonly TombstoneStore $store) {}

    /**
     * @param  list<string>  $walletIds  ascending
     */
    public function lockWallets(array $walletIds): void
    {
        $this->store->lockWallets($walletIds);
    }

    public function raise(string $walletId, int $position): void
    {
        $this->store->raiseWalletHorizon($walletId, $position);
    }
}
