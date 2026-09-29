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
