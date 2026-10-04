<?php

namespace App\Modules\Admin\Application\Enrolment;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Application\Dto\IssuedRecoveryCodes;
use App\Modules\Admin\Domain\Operators\AdminAction;
use Illuminate\Support\Facades\DB;

/**
 * A new set of ten recovery codes from the security page; every earlier code
 * stops working at once (FR-024).
 */
final class RegenerateRecoveryCodes
{
    public function __construct(
        private readonly IssueRecoveryCodes $issue,
        private readonly AdminAuditService $audit,
    ) {}

    public function handle(string $operatorId): IssuedRecoveryCodes
    {
        return DB::transaction(function () use ($operatorId): IssuedRecoveryCodes {
            $issued = $this->issue->handle($operatorId);
            $this->audit->adminActionByOperator($operatorId, $operatorId, AdminAction::RecoveryCodesRegenerated);

            return $issued;
        });
    }
}
