<?php

namespace App\Modules\Reporting\Application\Dto;

/**
 * A wallet the caller belongs to whose currency is not the cross-wallet
 * report's: listed in `meta.excluded_wallets`, never converted or summed.
 */
final readonly class ExcludedWallet
{
    public function __construct(
        public string $walletId,
        public string $currency,
    ) {}
}
