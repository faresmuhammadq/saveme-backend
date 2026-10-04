<?php

namespace App\Modules\Admin\Domain\Operators\Refusals;

use RuntimeException;

final class OperatorLocked extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Operator sign-in is locked.');
    }

    public function minutes(): int
    {
        return max(1, (int) ceil($this->retryAfterSeconds / 60));
    }
}
