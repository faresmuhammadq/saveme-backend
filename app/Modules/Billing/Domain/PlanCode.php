<?php

namespace App\Modules\Billing\Domain;

use InvalidArgumentException;

/**
 * A plan's data key. Only Billing's entitlement resolution and its
 * persistence may use it (P-09 rule 3, enforced by tests/Arch/EntitlementsTest.php);
 * the API never exposes it, so no client can gate on it either.
 */
final readonly class PlanCode
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a plan code: [{$value}].");
        }
    }
}
