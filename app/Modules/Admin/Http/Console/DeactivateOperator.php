<?php

namespace App\Modules\Admin\Http\Console;

use App\Modules\Admin\Application\Operators\DeactivateOperator as Deactivate;
use App\Modules\Admin\Domain\Operators\Refusals\LastActiveOperator;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorCommandRefused;
use Illuminate\Console\Command;

/**
 * Deactivates an operator (spec 018, FR-005). The last active operator is
 * never deactivated.
 */
final class DeactivateOperator extends Command
{
    protected $signature = 'admin:operator:deactivate {email : The operator\'s work email}';

    protected $description = 'Deactivate a back-office operator';

    public function handle(Deactivate $deactivate): int
    {
        $email = (string) $this->argument('email');

        try {
            $deactivate->handle($email);
        } catch (LastActiveOperator) {
            $this->error('This is the last active operator; create another first.');

            return self::FAILURE;
        } catch (OperatorCommandRefused $refused) {
            $this->error($refused->reason === 'already_deactivated' ? 'Already deactivated.' : 'No operator with this email.');

            return self::FAILURE;
        }

        $this->line("Operator deactivated: {$email}");

        return self::SUCCESS;
    }
}
