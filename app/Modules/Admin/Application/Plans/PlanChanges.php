<?php

namespace App\Modules\Admin\Application\Plans;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Billing\Application\Plans\PlanAdministration;
use App\Modules\Billing\Domain\Plans\PlanChangeSet;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use Illuminate\Support\Facades\DB;

/**
 * An operator saves or retires a plan (spec 021, FR-038): the Billing command
 * and its one `admin_action` entry commit together or not at all. The entry
 * names the changed fields and the reason, never a price or capability value.
 */
final class PlanChanges
{
    public function __construct(
        private readonly PlanAdministration $plans,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @return list<string> changed field names, sorted
     *
     * @throws InvalidReason
     */
    public function update(string $operatorId, string $planId, string $version, PlanChangeSet $changes, string $reason): array
    {
        $reason = self::reason($reason);

        return DB::transaction(function () use ($operatorId, $planId, $version, $changes, $reason): array {
            $fields = $this->plans->update($planId, $version, $changes);
            $this->audit->planChanged($operatorId, $planId, AdminAction::PlanUpdated, $fields, $reason);

            return $fields;
        });
    }

    /**
     * @throws InvalidReason
     */
    public function retire(string $operatorId, string $planId, string $version, string $reason): void
    {
        $reason = self::reason($reason);

        DB::transaction(function () use ($operatorId, $planId, $version, $reason): void {
            $this->plans->retire($planId, $version);
            $this->audit->planChanged($operatorId, $planId, AdminAction::PlanRetired, [], $reason);
        });
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        return $length < 1 || $length > 500 ? throw new InvalidReason : $reason;
    }
}
