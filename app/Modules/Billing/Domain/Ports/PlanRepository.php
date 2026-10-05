<?php

namespace App\Modules\Billing\Domain\Ports;

use App\Modules\Billing\Domain\Plans\PlanRecord;

interface PlanRepository
{
    /**
     * Active plans in catalogue order.
     *
     * @return list<PlanRecord>
     */
    public function activeCatalogue(): array;

    public function find(string $id): ?PlanRecord;

    /**
     * The active default (free) plan. A missing one is a deployment fault.
     */
    public function defaultPlan(): PlanRecord;

    /**
     * Every plan, active and retired, in catalogue order.
     *
     * @return list<PlanRecord>
     */
    public function all(): array;

    /**
     * The plan's edit version, or null when it does not exist.
     */
    public function version(string $id): ?string;

    /**
     * Stores the columns only if the plan is still at $version, and moves the
     * version on (spec 021, FR-035).
     *
     * @param  array{name: array{ar: string, en: string}, sort_order: int, features: array<string, bool|int|null>, prices: list<array<string, mixed>>}  $columns
     * @return bool whether this call stored them
     */
    public function updateIfVersion(string $id, string $version, array $columns): bool;

    /**
     * Retires an active, non-default plan only if it is still at $version.
     *
     * @return bool whether this call retired it
     */
    public function retireIfVersion(string $id, string $version): bool;
}
