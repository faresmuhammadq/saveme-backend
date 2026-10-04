<?php

namespace App\Modules\Admin\Application\Dto;

use App\Modules\Admin\Domain\Operators\SignInStage;

/**
 * A correct password: who, and which stage of sign-in comes next. Scalars
 * only, because the Http layer keeps these in the session.
 */
final readonly class PasswordAccepted
{
    public function __construct(
        public string $operatorId,
        public string $email,
        public SignInStage $stage,
        public int $epoch,
    ) {}
}
