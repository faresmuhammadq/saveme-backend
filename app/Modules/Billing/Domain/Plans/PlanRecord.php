<?php

namespace App\Modules\Billing\Domain\Plans;

use App\Modules\Billing\Domain\PlanCode;

/**
 * A plan from the catalogue: data, never code (ADR-0013 rule 1).
 */
final readonly class PlanRecord
{
    /**
     * @param  array{ar: string, en: string}  $name
     * @param  array<string, bool|int|null>  $features
     * @param  list<array{channel: string, product_id: ?string, amount_minor: int, currency: string}>  $prices
     */
    public function __construct(
        public string $id,
        public PlanCode $code,
        public array $name,
        public string $periodType,
        public ?int $periodDays,
        public bool $isDefault,
        public string $status,
        public int $sortOrder,
        public array $features,
        public array $prices,
    ) {}

    public function nameIn(string $locale): string
    {
        return $locale === 'ar' ? $this->name['ar'] : $this->name['en'];
    }
}
