<?php

namespace App\Modules\Billing\Domain\Ports;

/**
 * The keyed, searchable stand-in for a purchase token (spec 024, R-4):
 * lowercase hex HMAC-SHA256 under the Billing lookup key.
 */
interface TokenLookup
{
    public function for(#[\SensitiveParameter] string $token): string;
}
