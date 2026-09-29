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
}
