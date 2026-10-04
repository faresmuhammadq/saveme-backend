<?php

namespace App\Modules\Wallets\Application\Reports\Dto;

/**
 * A live wallet as a report sees it: its currency and the undated opening
 * balance that every range counts (spec 019, A-10). Never the cached balance.
 */
final readonly class ReportWallet
{
    public function __construct(
        public string $id,
        public string $currency,
        public int $openingBalanceMinor,
    ) {}
}
