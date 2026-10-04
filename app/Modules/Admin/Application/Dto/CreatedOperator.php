<?php

namespace App\Modules\Admin\Application\Dto;

/**
 * What the create and reset commands print, once. The temporary password is
 * plain here and nowhere else.
 */
final readonly class CreatedOperator
{
    public function __construct(public string $operatorId, public string $email, public string $temporaryPassword) {}
}
