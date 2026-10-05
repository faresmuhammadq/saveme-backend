<?php

namespace App\Modules\Admin\Application\Claims;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Billing\Application\Claims\ClaimDetail;
use App\Modules\Billing\Application\Claims\ClaimQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opening a claim review is a recorded read (spec 021, FR-017, R-3; spec 020
 * FR-045): the `claim_address_read` entry is written before the address is
 * returned, in the same transaction, so a failed write shows no address.
 * An absent claim writes nothing.
 */
final class ClaimReading
{
    public function __construct(
        private readonly ClaimQuery $claims,
        private readonly AdminAuditService $audit,
    ) {}

    public function open(string $operatorId, string $claimId): ?ClaimDetail
    {
        if (! Str::isUuid($claimId) || $this->claims->reviewState($claimId) === null) {
            return null;
        }

        return DB::transaction(function () use ($operatorId, $claimId): ?ClaimDetail {
            $this->audit->claimReadByOperator($operatorId, $claimId, AdminAction::ClaimAddressRead);

            return $this->claims->detail($claimId);
        });
    }
}
