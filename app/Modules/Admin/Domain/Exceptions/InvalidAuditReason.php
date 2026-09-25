<?php

namespace App\Modules\Admin\Domain\Exceptions;

use InvalidArgumentException;

final class InvalidAuditReason extends InvalidArgumentException
{
    private function __construct(public readonly string $rule, string $message)
    {
        parent::__construct($message);
    }

    public static function blank(): self
    {
        return new self('blank', 'blank: an audited action needs a reason.');
    }

    public static function tooLong(int $max): self
    {
        return new self('too_long', "too_long: a reason is at most {$max} characters.");
    }
}
