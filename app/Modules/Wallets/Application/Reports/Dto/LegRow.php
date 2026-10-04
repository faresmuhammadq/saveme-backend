<?php

namespace App\Modules\Wallets\Application\Reports\Dto;

/**
 * The sum of one wallet's legs of one kind in a range. For a transfer,
 * `otherWalletId` is the wallet on the other side; the sign of the amount is
 * the direction (negative leaves the wallet).
 */
final readonly class LegRow
{
    public function __construct(
        public string $walletId,
        public string $kind,
        public ?string $otherWalletId,
        public int $amountMinor,
    ) {}
}
