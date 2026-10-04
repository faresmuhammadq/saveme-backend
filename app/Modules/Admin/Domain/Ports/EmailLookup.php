<?php

namespace App\Modules\Admin\Domain\Ports;

interface EmailLookup
{
    public function lookup(string $email): string;
}
