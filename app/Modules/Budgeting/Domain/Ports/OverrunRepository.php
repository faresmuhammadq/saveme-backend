<?php

namespace App\Modules\Budgeting\Domain\Ports;

use DateTimeImmutable;

interface OverrunRepository
{
    /**
     * Records that the budget crossed its limit in the period, unless it
     * already did.
     *
     * @return bool whether this call recorded it (and so must announce it)
     */
    public function recordOnce(string $budgetId, string $userId, string $periodStart, int $threshold, DateTimeImmutable $at): bool;
}
