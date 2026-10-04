<?php

namespace App\Modules\Admin\Application\Reenrolment;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;
use Illuminate\Support\Facades\DB;

/**
 * Closes every request past its 72 hours, so "expired" has an instant
 * without depending on someone looking (spec 018, R-13).
 */
final class ExpireReenrolmentRequests
{
    public function __construct(
        private readonly ReenrolmentStore $requests,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @return int how many requests were expired
     */
    public function handle(): int
    {
        $expired = 0;
        $now = now()->toDateTimeImmutable();

        foreach ($this->requests->dueForExpiry($now) as $due) {
            DB::transaction(function () use ($due, $now, &$expired): void {
                $request = $this->requests->lockForUpdate($due->id);

                if ($request === null || $request->state !== ReenrolmentState::Open) {
                    return;
                }

                $this->requests->close($request->id, ReenrolmentState::Expired, null, $now);
                $this->audit->adminActionBySystem($request->operatorId, AdminAction::ReenrolmentExpired, $request->id);
                $expired++;
            });
        }

        return $expired;
    }
}
