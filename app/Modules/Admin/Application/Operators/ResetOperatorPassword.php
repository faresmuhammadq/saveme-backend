<?php

namespace App\Modules\Admin\Application\Operators;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Application\Dto\CreatedOperator;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorCommandRefused;
use App\Modules\Admin\Domain\Operators\TemporaryPassword;
use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues a new temporary password for a forgotten one (FR-063). The enrolled
 * secret and recovery codes are untouched, so an enrolled operator still
 * passes the second factor before choosing the new password; every session
 * ends on its next request because the epoch moves.
 */
final class ResetOperatorPassword
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly EmailLookup $lookups,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws OperatorCommandRefused
     */
    public function handle(string $email): CreatedOperator
    {
        $operator = $this->operators->findByLookup($this->lookups->lookup($email));

        if ($operator === null) {
            throw new OperatorCommandRefused('not_found');
        }

        if (! $operator->canSignIn()) {
            throw new OperatorCommandRefused('deactivated');
        }

        $temporary = TemporaryPassword::generate();

        DB::transaction(function () use ($operator, $temporary): void {
            $this->operators->issueTemporaryPassword(
                $operator->id,
                Hash::make($temporary),
                now()->addHours((int) config('admin.auth.temporary_password_hours'))->toDateTimeImmutable(),
            );

            $this->audit->adminActionBySystem($operator->id, AdminAction::TemporaryPasswordIssued);
        });

        return new CreatedOperator($operator->id, $operator->email, $temporary);
    }
}
