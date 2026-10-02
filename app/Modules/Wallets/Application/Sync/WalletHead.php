<?php

namespace App\Modules\Wallets\Application\Sync;

/**
 * One wallet's scope head and purge horizon (ADR-0021): what the phone
 * compares its stored sync position with.
 */
final readonly class WalletHead
{
    public function __construct(
        public string $walletId,
        public int $head,
        public int $purged,
    ) {}
}
