<?php

namespace App\Modules\Billing\Domain\Claims;

use DateTimeImmutable;

/**
 * A claim about to be stored: the address and receipt arrive already sealed.
 */
final readonly class NewClaim
{
    public function __construct(
        public string $id,
        public string $planId,
        public int $amountMinor,
        public string $currency,
        public string $reference,
        public string $addressSealed,
        public string $addressLookup,
        public string $locale,
        public ?string $receiptPath,
        public ?ReceiptMediaType $receiptMediaType,
        public ?int $receiptBytes,
        public DateTimeImmutable $expiresAt,
    ) {}
}
