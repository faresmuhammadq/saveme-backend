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
 * Creates an operator from the command line, the only way one is made
 * (spec 018, FR-003, FR-004a).
 */
final class CreateOperator
{
    public const NAME_MAX = 80;

    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly EmailLookup $lookups,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws OperatorCommandRefused
     */
    public function handle(string $email, string $displayName): CreatedOperator
    {
        $email = trim($email);
        $displayName = trim($displayName);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new OperatorCommandRefused('invalid_email');
        }

        if ($displayName === '' || mb_strlen($displayName) > self::NAME_MAX) {
            throw new OperatorCommandRefused('name_required');
        }

        if ($this->operators->findByLookup($this->lookups->lookup($email)) !== null) {
            throw new OperatorCommandRefused('duplicate');
        }

        $temporary = TemporaryPassword::generate();

        return DB::transaction(function () use ($email, $displayName, $temporary): CreatedOperator {
            $operator = $this->operators->create(
                $email,
                $displayName,
                Hash::make($temporary),
                now()->addHours((int) config('admin.auth.temporary_password_hours'))->toDateTimeImmutable(),
            );

            $this->audit->adminActionBySystem($operator->id, AdminAction::OperatorCreated);

            return new CreatedOperator($operator->id, $email, $temporary);
        });
    }
}
