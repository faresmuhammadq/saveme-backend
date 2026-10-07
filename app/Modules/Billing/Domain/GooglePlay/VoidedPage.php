<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * One page of Google's voided-purchases list, or the fact that it could not
 * be read.
 */
final readonly class VoidedPage
{
    /**
     * @param  list<VoidedPurchase>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextPageToken,
        public bool $available = true,
    ) {}

    public static function unavailable(): self
    {
        return new self([], null, false);
    }
}
