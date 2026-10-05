<?php

namespace App\Modules\Identity\Application\Figures;

use App\Modules\Identity\Domain\Ports\UserRepository;
use DateTimeImmutable;

/**
 * What the back-office overview may ask Identity: counts of accounts that
 * still exist, computed on each call (spec 021, R-11).
 */
final class AccountFigures
{
    public function __construct(private readonly UserRepository $users) {}

    public function compute(DateTimeImmutable $now): AccountFigureSet
    {
        $counts = $this->users->figures($now);

        return new AccountFigureSet(
            total: $counts['total'],
            verified: $counts['verified'],
            pendingDeletion: $counts['pending_deletion'],
            newInSevenDays: $counts['new_7_days'],
            newInThirtyDays: $counts['new_30_days'],
        );
    }
}
