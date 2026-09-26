<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * The keyed lookup value for an address: every search by address goes
 * through it, never through a readable address (baseline, Finding a person).
 */
interface AddressLookup
{
    public function for(string $address): string;
}
