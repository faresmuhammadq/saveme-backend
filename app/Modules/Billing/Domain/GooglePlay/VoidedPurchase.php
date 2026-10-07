<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * One purchase Google lists as voided (refunded, charged back or revoked).
 */
final readonly class VoidedPurchase
{
    public function __construct(
        #[\SensitiveParameter] public string $purchaseToken,
        public string $reason,
    ) {}
}
