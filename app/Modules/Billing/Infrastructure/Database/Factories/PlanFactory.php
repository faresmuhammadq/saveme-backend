<?php

namespace App\Modules\Billing\Infrastructure\Database\Factories;

use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Infrastructure\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A non-default active plan with every capability declared.
 *
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $features = [];
        foreach (Feature::cases() as $feature) {
            $features[$feature->value] = $feature->isQuota() ? 3 : true;
        }

        return [
            'code' => 'test_'.Str::lower(Str::random(8)),
            'name' => ['en' => 'Test plan', 'ar' => 'باقة تجريبية'],
            'period_type' => 'monthly',
            'period_days' => null,
            'is_default' => false,
            'status' => 'active',
            'sort_order' => 99,
            'features' => $features,
            'prices' => [],
        ];
    }
}
