<?php

namespace App\Modules\Notifications\Domain\Ports;

interface TokenLookup
{
    /**
     * The keyed lookup value of a push token (hex), for the one-token-one-
     * registration rule.
     */
    public function for(#[\SensitiveParameter] string $token): string;
}
