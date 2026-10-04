<?php

namespace App\Modules\Wallets\Application\Reports\Dto;

/**
 * The amount of scheduled occurrences of one shape on one day (spec 019,
 * research R-6). Occurrences have no postings, so the amount is the figure.
 */
final readonly class ScheduledRow
{
    /**
     * @param  string  $date  YYYY-MM-DD
     */
    public function __construct(
        public string $date,
        public string $kind,
        public ?string $categoryId,
        public ?string $systemKey,
        public string $walletId,
        public ?string $counterWalletId,
        public int $amountMinor,
    ) {}
}
