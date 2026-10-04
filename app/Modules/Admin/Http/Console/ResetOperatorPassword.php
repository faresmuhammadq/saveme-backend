<?php

namespace App\Modules\Admin\Http\Console;

use App\Modules\Admin\Application\Operators\ResetOperatorPassword as Reset;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorCommandRefused;
use Illuminate\Console\Command;

/**
 * Issues a new temporary password for a forgotten one (spec 018, FR-063).
 * It never touches the second factor.
 */
final class ResetOperatorPassword extends Command
{
    protected $signature = 'admin:operator:reset-password {email : The operator\'s work email}';

    protected $description = 'Issue a new temporary password for a back-office operator';

    public function handle(Reset $reset): int
    {
        try {
            $issued = $reset->handle((string) $this->argument('email'));
        } catch (OperatorCommandRefused $refused) {
            $this->error($refused->reason === 'deactivated' ? 'This operator is deactivated.' : 'No operator with this email.');

            return self::FAILURE;
        }

        $this->line("Temporary password (shown once): {$issued->temporaryPassword}");

        return self::SUCCESS;
    }
}
