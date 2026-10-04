<?php

namespace App\Modules\Admin\Application\Reenrolment;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Application\Dto\ReenrolmentOutcome;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Lost your device?": opens a request that two other operators must approve
 * (FR-030). One open request per operator; opening is throttled to five an
 * hour-quarter (FR-053).
 */
final class RequestReenrolment
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly ReenrolmentStore $requests,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws OperatorLocked|PendingSignInExpired
     */
    public function handle(string $operatorId): ReenrolmentOutcome
    {
        $key = 'admin-reenrol:'.$operatorId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new OperatorLocked(max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 15 * 60);

        return DB::transaction(function () use ($operatorId): ReenrolmentOutcome {
            $operator = $this->operators->lockForUpdate($operatorId);

            if ($operator === null || $operator->state !== OperatorState::Active) {
                throw new PendingSignInExpired;
            }

            $now = now()->toDateTimeImmutable();
            $open = $this->requests->findOpenFor($operatorId);

            if ($open !== null && $open->expiresAt > $now) {
                return new ReenrolmentOutcome($open->id, $open->expiresAt->format('Y-m-d\TH:i:s.uP'), true, $this->enoughApprovers($operatorId));
            }

            if ($open !== null) {
                $this->requests->close($open->id, ReenrolmentState::Expired, null, $now);
                $this->audit->adminActionBySystem($operatorId, AdminAction::ReenrolmentExpired, $open->id);
            }

            $request = $this->requests->open($operatorId, $now, $now->modify('+'.(int) config('admin.auth.reenrolment_hours').' hours'));
            $this->audit->adminActionByOperator($operatorId, $operatorId, AdminAction::ReenrolmentRequested, $request->id);

            return new ReenrolmentOutcome($request->id, $request->expiresAt->format('Y-m-d\TH:i:s.uP'), false, $this->enoughApprovers($operatorId));
        });
    }

    private function enoughApprovers(string $operatorId): bool
    {
        return $this->operators->countEligibleApprovers($operatorId) >= 2;
    }
}
