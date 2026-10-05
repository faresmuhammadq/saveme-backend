<?php

namespace App\Modules\Billing\Application\Plans;

/**
 * One plan as the back office shows it (spec 021, FR-031): both names, the
 * period, status, order, capability values, prices and how many people and
 * submitted claims it touches. Never the plan code (ADR-0013 rule 6).
 */
final readonly class PlanAdminView
{
    /**
     * @param  array{ar: string, en: string}  $name
     * @param  array<string, bool|int|null>  $features
     * @param  array{amount_minor: int, currency: string}|null  $instapayPrice
     * @param  list<array{product_id: ?string, amount_minor: int, currency: string}>  $googlePlayPrices
     * @param  int|null  $governedPeople  null for the default plan, which governs everyone without a granting subscription
     */
    public function __construct(
        public string $id,
        public array $name,
        public string $periodType,
        public ?int $periodDays,
        public bool $isDefault,
        public string $status,
        public int $sortOrder,
        public array $features,
        public ?array $instapayPrice,
        public array $googlePlayPrices,
        public string $version,
        public ?int $governedPeople,
        public int $submittedClaims,
    ) {}

    /**
     * @return array{id: string, name: array{ar: string, en: string}, period_type: string, period_days: ?int, is_default: bool, status: string, sort_order: int, features: array<string, bool|int|null>, instapay_price: ?array{amount_minor: int, currency: string}, google_play_prices: list<array{product_id: ?string, amount_minor: int, currency: string}>, version: string, governed_people: ?int, submitted_claims: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'period_type' => $this->periodType,
            'period_days' => $this->periodDays,
            'is_default' => $this->isDefault,
            'status' => $this->status,
            'sort_order' => $this->sortOrder,
            'features' => $this->features,
            'instapay_price' => $this->instapayPrice,
            'google_play_prices' => $this->googlePlayPrices,
            'version' => $this->version,
            'governed_people' => $this->governedPeople,
            'submitted_claims' => $this->submittedClaims,
        ];
    }
}
