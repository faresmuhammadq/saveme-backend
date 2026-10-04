<?php

namespace App\Modules\Admin\Application\Audit;

use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Identity\Application\Audit\Details\AuditDetail;

/**
 * The closed `detail` of an `admin_action` entry (spec 018, R-13). The
 * request key is `reenrolment_request_id`, never `request_id`, which is the
 * HTTP correlation column.
 */
final readonly class AdminActionDetail implements AuditDetail
{
    public function __construct(
        private AdminAction $action,
        private ?string $reenrolmentRequestId = null,
        private ?AuditReason $reason = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'action' => $this->action->value,
            'reenrolment_request_id' => $this->reenrolmentRequestId,
            'reason' => $this->reason?->value(),
        ], fn (?string $value): bool => $value !== null);
    }
}
