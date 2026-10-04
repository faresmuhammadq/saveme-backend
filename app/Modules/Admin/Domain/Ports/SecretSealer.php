<?php

namespace App\Modules\Admin\Domain\Ports;

/**
 * Authenticated encryption of operator values, bound to the operator and the
 * field (spec 018, R-10).
 */
interface SecretSealer
{
    public function seal(string $plaintext, string $operatorId, string $field): string;

    public function open(string $sealed, string $operatorId, string $field): string;
}
