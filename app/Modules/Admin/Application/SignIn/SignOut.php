<?php

namespace App\Modules\Admin\Application\SignIn;

use App\Modules\Admin\Application\Audit\AdminAuditService;

/**
 * Sign-out is an act, so it is audited; idle and absolute expiry are not
 * (FR-083).
 */
final class SignOut
{
    public function __construct(private readonly AdminAuditService $audit) {}

    public function handle(string $operatorId): void
    {
        $this->audit->signedOut($operatorId);
    }
}
