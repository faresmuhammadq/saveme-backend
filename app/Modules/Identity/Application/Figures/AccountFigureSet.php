<?php

namespace App\Modules\Identity\Application\Figures;

/**
 * Population counts of accounts, nothing about any one person (spec 021, FR-010).
 */
final readonly class AccountFigureSet
{
    public function __construct(
        public int $total,
        public int $verified,
        public int $pendingDeletion,
        public int $newInSevenDays,
        public int $newInThirtyDays,
    ) {}
}
