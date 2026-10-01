<?php

namespace App\Modules\Wallets\Domain\Ports;

/**
 * The wallet scope's change counter (ADR-0008 rules 2-5). The wallet row is
 * the counter; every call returns the next value and must be made with that
 * row already locked, so values are commit-ordered and never shared.
 */
interface ScopeSequence
{
    public function nextFor(string $walletId): int;
}
