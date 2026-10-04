<?php

namespace App\Modules\Admin\Application\Reenrolment;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use App\Modules\Admin\Domain\Operators\Refusals\ReenrolmentRefused;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;
use Illuminate\Support\Facades\DB;

/**
 * Any other operator may decline a request, with a reason, closing it
 * (FR-032). The requester may then open a new one.
 */
final class DeclineReenrolment
{
    public function __construct(
        private readonly ReenrolmentStore $requests,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws ReenrolmentRefused
     */
    public function handle(string $deciderId, string $requestId, AuditReason $reason): void
    {
        DB::transaction(function () use ($deciderId, $requestId, $reason): void {
            $request = $this->requests->lockForUpdate($requestId);

            if ($request === null || $request->state !== ReenrolmentState::Open) {
                throw new ReenrolmentRefused('closed');
            }

            $now = now()->toDateTimeImmutable();

            if ($request->expiresAt <= $now) {
                throw new ReenrolmentRefused('expired', $request->expiresAt);
            }

            if ($request->operatorId === $deciderId) {
                throw new ReenrolmentRefused('own_request');
            }

            $this->requests->close($requestId, ReenrolmentState::Declined, $deciderId, $now);
            $this->audit->adminActionByOperator($deciderId, $request->operatorId, AdminAction::ReenrolmentDeclined, $requestId, $reason);
        });
    }
}
