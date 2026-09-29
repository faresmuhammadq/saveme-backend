<?php

namespace App\Modules\Billing\Application\Entitlements;

use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use LogicException;

/**
 * One person's capabilities, from their governing plan (derived, never stored).
 */
final readonly class Entitlements
{
    public function __construct(private PlanRecord $plan) {}

    public function allows(Feature $feature): bool
    {
        $value = $this->value($feature);

        return $feature->isQuota() ? ($value === null || $value > 0) : $value === true;
    }

    /**
     * @return int|null null = unlimited
     */
    public function quota(Feature $feature): ?int
    {
        if (! $feature->isQuota()) {
            throw new LogicException("[{$feature->value}] is a flag, not a quota.");
        }

        $value = $this->value($feature);

        return $value === null ? null : (int) $value;
    }

    public function summary(): EntitlementSummary
    {
        $features = [];
        foreach (Feature::cases() as $feature) {
            $features[$feature->value] = $this->value($feature);
        }

        return new EntitlementSummary($this->plan->id, $this->plan->name, $features);
    }

    /**
     * The plan's value for a capability. A declared null is a real value
     * (unlimited); only an undeclared capability falls back to denied.
     */
    private function value(Feature $feature): bool|int|null
    {
        return array_key_exists($feature->value, $this->plan->features)
            ? $this->plan->features[$feature->value]
            : $feature->deniedValue();
    }
}
