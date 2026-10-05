<?php

namespace App\Modules\Billing\Domain\Plans;

/**
 * What an operator wants a plan to look like (spec 021, FR-032): the values
 * as typed, not yet checked. `PlanEdit` validates them against the stored plan.
 */
final readonly class PlanChangeSet
{
    /**
     * @param  array{en: mixed, ar: mixed}  $name
     * @param  array{amount_minor: mixed, currency: mixed}|null  $instapayPrice  null removes the InstaPay price
     * @param  array<string, mixed>  $features  capability key to value; keys left out stay as stored
     */
    public function __construct(
        public array $name,
        public mixed $sortOrder,
        public ?array $instapayPrice,
        public array $features,
    ) {}

    /**
     * Built from the editor's draft: `name` {en, ar}, `sort_order`,
     * `instapay_price` {amount_minor, currency}|null, `features` map.
     *
     * @param  array<string, mixed>  $draft
     */
    public static function fromDraft(array $draft): self
    {
        $name = is_array($draft['name'] ?? null) ? $draft['name'] : [];
        $price = is_array($draft['instapay_price'] ?? null) ? $draft['instapay_price'] : null;

        return new self(
            ['en' => $name['en'] ?? null, 'ar' => $name['ar'] ?? null],
            $draft['sort_order'] ?? null,
            $price === null ? null : ['amount_minor' => $price['amount_minor'] ?? null, 'currency' => $price['currency'] ?? null],
            is_array($draft['features'] ?? null) ? $draft['features'] : [],
        );
    }
}
