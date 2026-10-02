<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * A person's budget start day history (spec 015, FR-007): server-internal,
 * written only with the profile.
 */
interface BudgetStartDayHistory
{
    /**
     * The history, the entry with no `applies_from` first, then by date.
     *
     * @return list<array{start_day: int, applies_from: ?string}>
     */
    public function entriesOf(string $userId): array;

    /**
     * Records a changed start day: the day in force before the save as the
     * first entry when the history is empty, removes entries applying after
     * `$today` (changes that never took effect), then appends the new day.
     *
     * @param  string  $appliesFrom  YYYY-MM-DD
     * @param  string  $today  YYYY-MM-DD
     */
    public function record(string $userId, int $previousDay, int $newDay, string $appliesFrom, string $today): void;

    /**
     * The profile's current start day; 1 without a profile.
     */
    public function currentDayOf(string $userId): int;
}
