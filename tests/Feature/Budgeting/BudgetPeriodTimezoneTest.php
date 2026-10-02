<?php

use App\Modules\Budgeting\Application\BudgetQueryService;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * Period membership by calendar date; "today" by the owner's profile timezone
 * (spec 015, FR-009, P-12, ADR-0011 rules 6-8; US5 S2, S4-S5).
 */

test('owners in different timezones count a transaction dated 2026-09-30 in the same period', function () {
    freezeClock('2026-10-15 10:00:00');
    $cairo = person('cairo@example.test');
    $newYork = person('ny@example.test');
    Profile::query()->where('user_id', $newYork->id)->update(['timezone' => 'America/New_York']);

    foreach ([$cairo, $newYork] as $owner) {
        $wallet = ownedWallet($owner->id);
        spend($owner->id, $wallet->id, 5000, ['date' => '2026-09-30']);
        $budget = createBudget($owner->id, ['category_id' => platformCategoryId()]);

        $progress = budgetProgress($owner->id, $budget->id, '2026-09-30');

        expect($progress->spent->amountMinor)->toBe(5000)
            ->and($progress->period->start)->toBe('2026-09-01')
            ->and($progress->period->end)->toBe('2026-09-30');
    }
});

test('the default period follows each owner\'s today at an instant near midnight', function () {
    // 2026-10-31 22:30 UTC: Cairo (UTC+2) is already 2026-11-01 00:30, New York (UTC-4) is 2026-10-31 18:30.
    freezeClock('2026-10-31 22:30:00');
    $cairo = person('cairo@example.test');
    $newYork = person('ny@example.test');
    Profile::query()->where('user_id', $newYork->id)->update(['timezone' => 'America/New_York']);

    $cairoBudget = createBudget($cairo->id);
    $newYorkBudget = createBudget($newYork->id);

    $inCairo = budgetProgress($cairo->id, $cairoBudget->id);
    $inNewYork = budgetProgress($newYork->id, $newYorkBudget->id);

    expect($inCairo->period->start)->toBe('2026-11-01')->and($inCairo->daysElapsed)->toBe(1)
        ->and($inNewYork->period->start)->toBe('2026-10-01')->and($inNewYork->daysElapsed)->toBe(31);
});

test('without a profile the default timezone and start day 1 apply', function () {
    freezeClock('2026-10-31 22:30:00');
    $bare = User::factory()->create();
    $budget = createBudget($bare->id);

    $progress = budgetProgress($bare->id, $budget->id);

    expect($progress->period->start)->toBe(now()->setTimezone(config('identity.default_timezone'))->format('Y-m-01'));
});

test('after a real start day change, progress before, during and after the change keeps each period\'s dates', function () {
    freezeClock('2026-10-02 10:00:00');
    $owner = person('owner@example.test');
    $credential = signInAs('owner@example.test')->json('data.credential');
    $budget = createBudget($owner->id);

    saveProfile($credential, profileBody(['budget_period_start_day' => 25]))->assertOk();

    $query = app(BudgetQueryService::class);
    $period = fn (string $date): string => ($p = $query->progressOf($owner->id, $budget->id, $date)->period)->start.'..'.$p->end;

    expect($period('2026-10-12'))->toBe('2026-10-01..2026-10-24')
        ->and($period('2026-10-30'))->toBe('2026-10-25..2026-11-24')
        ->and($period('2026-09-15'))->toBe('2026-09-01..2026-09-30')
        ->and(budgetProgress($owner->id, $budget->id)->period->start)->toBe('2026-10-01');
});
