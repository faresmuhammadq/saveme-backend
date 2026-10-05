<?php

namespace App\Modules\Billing\Application\Claims;

/**
 * One page of the claim queue: no address in any item (spec 021, FR-015).
 */
final readonly class ClaimPage
{
    /**
     * @param  list<ClaimSummary>  $items
     * @param  ?string  $next  the cursor for the following page, or null on the last
     */
    public function __construct(
        public array $items,
        public ?string $next,
    ) {}
}
