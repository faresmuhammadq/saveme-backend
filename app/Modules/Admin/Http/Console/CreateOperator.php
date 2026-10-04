<?php

namespace App\Modules\Admin\Http\Console;

use App\Modules\Admin\Application\Operators\CreateOperator as Create;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorCommandRefused;
use Illuminate\Console\Command;

/**
 * Creates an operator, the only way one is made (spec 018, FR-003; contracts/
 * commands-and-audit.md). Prints the temporary password once; nothing else
 * about the password reaches output or logs.
 */
final class CreateOperator extends Command
{
    protected $signature = 'admin:operator:create {email : The operator\'s work email} {--name= : The display name, 1 to 80 characters}';

    protected $description = 'Create a back-office operator and print a temporary password once';

    public function handle(Create $create): int
    {
        try {
            $created = $create->handle((string) $this->argument('email'), (string) $this->option('name'));
        } catch (OperatorCommandRefused $refused) {
            $this->error(match ($refused->reason) {
                'duplicate' => 'An operator with this email already exists.',
                'invalid_email' => 'The email is not valid.',
                default => 'A display name is required.',
            });

            return self::FAILURE;
        }

        $this->line("Operator created: {$created->email}");
        $this->line("Temporary password (shown once): {$created->temporaryPassword}");

        return self::SUCCESS;
    }
}
