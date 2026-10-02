<?php

namespace App\Modules\Identity\Application\Profiles;

use App\Modules\Identity\Domain\Ports\BudgetStartDayHistory;
use App\Modules\Identity\Domain\Ports\ProfileRepository;

/**
 * What Budgeting needs of a person's profile (spec 015, FR-007, FR-009): the
 * start day history, the day in force now, and the timezone that decides
 * "today" (the configured default without a profile).
 */
final class BudgetStartDayQuery
{
    public function __construct(
        private readonly BudgetStartDayHistory $history,
        private readonly ProfileRepository $profiles,
    ) {}

    /**
     * @return list<array{start_day: int, applies_from: ?string}>
     */
    public function entriesOf(string $userId): array
    {
        return $this->history->entriesOf($userId);
    }

    public function currentDayOf(string $userId): int
    {
        return $this->history->currentDayOf($userId);
    }

    public function timezoneOf(string $userId): string
    {
        return $this->profiles->timezoneOf($userId);
    }
}
