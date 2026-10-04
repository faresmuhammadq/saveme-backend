<?php

namespace App\Modules\Admin\Domain\Ports;

interface RecoveryCodeHasher
{
    /**
     * @param  string  $normalised  a normalised recovery code
     */
    public function hash(string $normalised): string;
}
