<?php

namespace App\Modules\Admin\Application\Audit;

use App\Modules\Identity\Application\Audit\Details\AuditDetail;

final readonly class OperatorSignInDetail implements AuditDetail
{
    /**
     * @param  'code'|'recovery_code'  $factor
     */
    public function __construct(private string $factor, private int $recoveryCodesLeft) {}

    public function toArray(): array
    {
        return ['factor' => $this->factor, 'recovery_codes_left' => $this->recoveryCodesLeft];
    }
}
