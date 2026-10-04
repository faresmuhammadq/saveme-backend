<?php

namespace App\Modules\Admin\Application\Dto;

use App\Modules\Admin\Domain\Operators\OperatorState;

final readonly class PasswordChangeResult
{
    public function __construct(public int $epoch, public OperatorState $state) {}
}
