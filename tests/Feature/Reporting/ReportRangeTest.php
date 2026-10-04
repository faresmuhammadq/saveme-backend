<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Domain\ReportRange;

/*
 * The Report Range (spec 019, US1 S1 and S6, FR-002, FR-003, P-12): the
 * caller's Budget Period by default, an explicit span otherwise, membership
 * by calendar date only.
 */

function rangeOfSummary(string $userId, string $walletId, ReportRequest $request): string
{
    $range = app(ReportQueryService::class)->walletSummary($userId, $walletId, $request)->range;

    return $range->from.'..'.$range->to;
}

test('the default range is the caller\'s Budget Period containing today, for day 1 and day 25', function () {
    freezeClock('2026-10-15 10:00:00');
    $owner = person('owner@example.test');
    $credential = signInAs('owner@example.test')->json('data.credential');
    $wallet = ownedWallet($owner->id);

    expect(rangeOfSummary($owner->id, $wallet->id, new ReportRequest))->toBe('2026-10-01..2026-10-31');

    saveProfile($credential, profileBody(['budget_period_start_day' => 25]))->assertOk();
    freezeClock('2026-10-30 10:00:00');

    expect(rangeOfSummary($owner->id, $wallet->id, new ReportRequest))->toBe('2026-10-25..2026-11-24')
        ->and(rangeOfSummary($owner->id, $wallet->id, new ReportRequest(date: '2026-10-12')))->toBe('2026-10-01..2026-10-24');
});

test('a date picks the Budget Period containing it, and from and to are exact', function () {
    freezeClock('2026-10-15 10:00:00');
    $owner = person('owner@example.test');
    $wallet = ownedWallet($owner->id);

    expect(rangeOfSummary($owner->id, $wallet->id, new ReportRequest(date: '2026-09-10')))->toBe('2026-09-01..2026-09-30')
        ->and(rangeOfSummary($owner->id, $wallet->id, new ReportRequest(range: ReportRange::explicit('2026-09-05', '2026-10-20'))))->toBe('2026-09-05..2026-10-20');
});

test('the timezone decides only today, not which transactions belong to a range', function () {
    // 2026-10-31 22:30 UTC is already 2026-11-01 in Cairo but still 2026-10-31 in New York.
    freezeClock('2026-10-31 22:30:00');
    $cairo = person('cairo@example.test');
    $newYork = person('ny@example.test');
    Profile::query()->where('user_id', $newYork->id)->update(['timezone' => 'America/New_York']);
    $wallet = ownedWallet($cairo->id);
    addMember($wallet->id, $newYork->id, 'editor');
    spend($cairo->id, $wallet->id, 500, ['date' => '2026-10-31']);
    spend($cairo->id, $wallet->id, 70, ['date' => '2026-11-01']);

    $service = app(ReportQueryService::class);
    $cairoDefault = $service->walletSummary($cairo->id, $wallet->id, new ReportRequest);
    $newYorkDefault = $service->walletSummary($newYork->id, $wallet->id, new ReportRequest);

    expect($cairoDefault->range->from)->toBe('2026-11-01')
        ->and($newYorkDefault->range->from)->toBe('2026-10-01')
        ->and($cairoDefault->figures->expenses->amountMinor)->toBe(70)
        ->and($newYorkDefault->figures->expenses->amountMinor)->toBe(500);

    $explicit = new ReportRequest(range: ReportRange::explicit('2026-10-31', '2026-11-01'));

    expect($service->walletSummary($cairo->id, $wallet->id, $explicit)->figures->expenses->amountMinor)->toBe(570)
        ->and($service->walletSummary($newYork->id, $wallet->id, $explicit)->figures->expenses->amountMinor)->toBe(570);
});
