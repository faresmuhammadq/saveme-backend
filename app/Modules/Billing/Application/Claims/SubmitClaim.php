<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Domain\Claims\ReceiptImage;

/**
 * What the website sent, after the request validated its shape (spec 020,
 * FR-003). The address and reference are still as typed; the service
 * normalises them.
 */
final readonly class SubmitClaim
{
    /**
     * @param  'ar'|'en'  $locale  the language resolved from the request
     */
    public function __construct(
        public string $address,
        public string $planId,
        public int $amountMinor,
        public string $currency,
        public string $reference,
        public string $locale,
        public ?ReceiptImage $receipt = null,
    ) {}
}
