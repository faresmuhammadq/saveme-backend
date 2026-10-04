<?php

namespace App\Modules\Admin\Domain\Operators\Refusals;

use RuntimeException;

final class PasswordRuleBroken extends RuntimeException
{
    /**
     * @param  list<string>  $violations  OperatorPasswordPolicy violation names
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('The password breaks a rule.');
    }
}
