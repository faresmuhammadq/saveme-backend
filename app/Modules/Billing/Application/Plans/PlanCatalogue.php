<?php

namespace App\Modules\Billing\Application\Plans;

use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Billing\Domain\Ports\PlanRepository;

/**
 * The public catalogue: active plans, in order (spec 009, US2).
 */
final class PlanCatalogue
{
    public function __construct(private readonly PlanRepository $plans) {}

    /**
     * @return list<PlanRecord>
     */
    public function active(): array
    {
        return $this->plans->activeCatalogue();
    }

    /**
     * The plan a Google Play product id is sold as, active or retired (spec
     * 024, R-9, FR-043): a retired plan keeps mapping its product for renewals
     * and reconciliation. A product id is on at most one plan.
     */
    public function planForPlayProduct(string $productId): ?PlanRecord
    {
        foreach ($this->plans->all() as $plan) {
            foreach ($plan->prices as $price) {
                if ($price['channel'] === 'google_play' && $price['product_id'] === $productId) {
                    return $plan;
                }
            }
        }

        return null;
    }

    /**
     * Every Play product id the catalogue maps, active or retired plans (spec 024, FR-043).
     *
     * @return list<string>
     */
    public function playProductIds(): array
    {
        $ids = [];

        foreach ($this->plans->all() as $plan) {
            foreach ($plan->prices as $price) {
                if ($price['channel'] === 'google_play' && is_string($price['product_id'])) {
                    $ids[] = $price['product_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A catalogue item on the wire: never the plan code (ADR-0013 rule 6).
     *
     * @return array<string, mixed>
     */
    public static function toWire(PlanRecord $plan, string $locale): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->nameIn($locale),
            'period_type' => $plan->periodType,
            'period_days' => $plan->periodDays,
            'is_default' => $plan->isDefault,
            'features' => (object) $plan->features,
            'prices' => $plan->prices,
        ];
    }
}
