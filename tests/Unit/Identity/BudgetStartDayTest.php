<?php

use App\Modules\Identity\Domain\Preferences\BudgetStartDay;

/*
 * A new budget start day applies from the first date strictly after today
 * that falls on that day of the month (spec 008, FR-013, R-4).
 */

test('the new day applies from its next occurrence after today', function (int $day, string $today, string $from) {
    expect(BudgetStartDay::appliesFrom($day, new DateTimeImmutable($today)))->toBe($from);
})->with([
    'later this month' => [28, '2026-09-27', '2026-09-28'],
    'already passed this month' => [25, '2026-09-27', '2026-10-25'],
    'today itself is not after today' => [27, '2026-09-27', '2026-10-27'],
    'across a year end' => [1, '2026-12-15', '2027-01-01'],
    'from the end of February' => [28, '2027-02-28', '2027-03-28'],
]);

test('a day outside 1 to 28 is refused', function (int $day) {
    expect(fn () => BudgetStartDay::appliesFrom($day, new DateTimeImmutable('2026-09-27')))->toThrow(InvalidArgumentException::class);
})->with([0, 29]);
