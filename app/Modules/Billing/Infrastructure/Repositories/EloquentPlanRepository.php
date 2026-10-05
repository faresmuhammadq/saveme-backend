<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\PlanCode;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Infrastructure\Models\Plan;
use Illuminate\Support\Facades\DB;
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

    public function all(): array
    {
        return array_values(Plan::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Plan $plan): PlanRecord => self::record($plan))
            ->all());
    }

    public function version(string $id): ?string
    {
        $plan = Plan::query()->find($id, ['id', 'updated_at']);

        return $plan === null ? null : self::versionOf($plan);
    }

    public function updateIfVersion(string $id, string $version, array $columns): bool
    {
        return Plan::query()->whereKey($id)->where('updated_at', $version)->toBase()->update([
            'name' => json_encode($columns['name'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'sort_order' => $columns['sort_order'],
            'features' => json_encode($columns['features'], JSON_THROW_ON_ERROR),
            'prices' => json_encode($columns['prices'], JSON_THROW_ON_ERROR),
            'updated_at' => self::nextVersion(),
        ]) === 1;
    }

    public function retireIfVersion(string $id, string $version): bool
    {
        return Plan::query()->whereKey($id)->where('updated_at', $version)
            ->where('status', 'active')->where('is_default', false)
            ->toBase()->update(['status' => 'retired', 'updated_at' => self::nextVersion()]) === 1;
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
            version: self::versionOf($plan),
        );
    }

    private static function versionOf(Plan $plan): string
    {
        return $plan->updated_at->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * A new version is always later than the one it replaces, even when the
     * clock has not moved (a frozen clock in tests, two saves in one tick).
     */
    private static function nextVersion(): mixed
    {
        $now = now()->format('Y-m-d H:i:s.uP');

        /** @var literal-string $sql the instant is our own formatted clock reading, never input */
        $sql = "GREATEST('{$now}'::timestamptz, updated_at + interval '1 microsecond')";

        return DB::raw($sql);
    }
}
