<?php

namespace App\Modules\Billing\Application\Plans;

use App\Modules\Billing\Domain\Plans\PlanChangeSet;
use App\Modules\Billing\Domain\Plans\PlanEdit;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Refusals\NothingToSave;
use App\Modules\Billing\Domain\Refusals\PlanChangedElsewhere;
use App\Modules\Billing\Domain\Refusals\PlanEditInvalid;
use App\Modules\Billing\Domain\Refusals\PlanNotRetirable;
use Illuminate\Support\Facades\DB;

/**
 * What an operator may do to the catalogue (spec 021, FR-032 to FR-037): save
 * a plan's editable fields, or retire a non-default plan. Each is one unit of
 * work guarded by the version the operator edited, so a stale save changes
 * nothing. Existing claims and subscriptions are never touched. The audit
 * entry is written by Admin's `PlanChanges` in the same transaction.
 */
final class PlanAdministration
{
    public function __construct(private readonly PlanRepository $plans) {}

    /**
     * @return list<string> changed field names, sorted
     *
     * @throws PlanEditInvalid
     * @throws NothingToSave
     * @throws PlanChangedElsewhere
     */
    public function update(string $planId, string $version, PlanChangeSet $changes): array
    {
        return DB::transaction(function () use ($planId, $version, $changes): array {
            $plan = $this->plans->find($planId);

            if ($plan === null || $plan->version !== $version) {
                throw new PlanChangedElsewhere;
            }

            $edit = PlanEdit::of($plan, $changes);

            if (! $this->plans->updateIfVersion($planId, $version, $edit->columns)) {
                throw new PlanChangedElsewhere;
            }

            return $edit->fields;
        });
    }

    /**
     * @throws PlanNotRetirable the plan is unknown, the default, or already retired
     * @throws PlanChangedElsewhere
     */
    public function retire(string $planId, string $version): void
    {
        DB::transaction(function () use ($planId, $version): void {
            $plan = $this->plans->find($planId);

            if ($plan === null || $plan->isDefault || $plan->status !== 'active') {
                throw new PlanNotRetirable;
            }

            if ($plan->version !== $version || ! $this->plans->retireIfVersion($planId, $version)) {
                throw new PlanChangedElsewhere;
            }
        });
    }
}
