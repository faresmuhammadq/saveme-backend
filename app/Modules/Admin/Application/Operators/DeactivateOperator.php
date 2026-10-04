<?php

namespace App\Modules\Admin\Application\Operators;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use App\Modules\Admin\Domain\Operators\Refusals\LastActiveOperator;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorCommandRefused;
use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates an operator (FR-005): sessions end on their next request, an
 * open re-enrolment request is cancelled, and the last active operator is
 * never deactivated.
 */
final class DeactivateOperator
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly ReenrolmentStore $requests,
        private readonly EmailLookup $lookups,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws OperatorCommandRefused|LastActiveOperator
     */
    public function handle(string $email): void
    {
        $found = $this->operators->findByLookup($this->lookups->lookup($email));

        if ($found === null) {
            throw new OperatorCommandRefused('not_found');
        }

        DB::transaction(function () use ($found): void {
            $operator = $this->operators->lockForUpdate($found->id);

            if ($operator === null) {
                throw new OperatorCommandRefused('not_found');
            }

            if ($operator->state === OperatorState::Deactivated) {
                throw new OperatorCommandRefused('already_deactivated');
            }

            if ($operator->state === OperatorState::Active && $this->operators->countActive() <= 1) {
                throw new LastActiveOperator;
            }

            $open = $this->requests->findOpenFor($operator->id);

            if ($open !== null) {
                $this->requests->close($open->id, ReenrolmentState::Cancelled, null, now()->toDateTimeImmutable());
                $this->audit->adminActionBySystem($operator->id, AdminAction::ReenrolmentCancelled, $open->id);
            }

            $this->operators->deactivate($operator->id);
            $this->audit->adminActionBySystem($operator->id, AdminAction::OperatorDeactivated);
        });
    }
}
