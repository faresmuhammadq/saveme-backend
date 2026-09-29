<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\PlanCode;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Infrastructure\Models\Plan;
use RuntimeException;

final class EloquentPlanRepository implements PlanRepository
{
    public function activeCatalogue(): array
    {
        return array_values(Plan::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Plan $plan): PlanRecord => self::record($plan))
            ->all());
    }

    public function find(string $id): ?PlanRecord
    {
        $plan = Plan::query()->find($id);

        return $plan === null ? null : self::record($plan);
    }

    public function defaultPlan(): PlanRecord
    {
        $plan = Plan::query()->where('is_default', true)->where('status', 'active')->first()
            ?? throw new RuntimeException('No active default plan: seed the plan catalogue (BillingSeeder).');

        return self::record($plan);
    }

    private static function record(Plan $plan): PlanRecord
    {
        return new PlanRecord(
            id: $plan->id,
            code: new PlanCode($plan->code),
            name: $plan->name,
            periodType: $plan->period_type,
            periodDays: $plan->period_days,
            isDefault: $plan->is_default,
            status: $plan->status,
            sortOrder: $plan->sort_order,
            features: $plan->features,
            prices: $plan->prices,
        );
    }
}
