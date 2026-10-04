<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Domain\ReportRange;
use App\Modules\Wallets\Infrastructure\Models\Category;

/*
 * Identical figures for every member (spec 019, US4 S7, SC-004; ADR-0011 rule
 * 7, ADR-0014 rule 5): two members in different timezones read the same body,
 * and a platform category's total is scoped to the wallets read.
 */

test('two members in different timezones get identical bodies for the same explicit range', function () {
    freezeClock('2026-10-31 22:30:00');
    $owner = person('owner@example.test');
    $partner = person('partner@example.test');
    Profile::query()->where('user_id', $partner->id)->update(['timezone' => 'America/New_York']);
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $partner->id, 'editor');
    spend($owner->id, $wallet->id, 1500, ['date' => '2026-10-31']);
    spend($partner->id, $wallet->id, 2500, ['date' => '2026-11-01']);
    recordTransaction($partner->id, $wallet->id, incomeBody(['amount_minor' => 9000, 'date' => '2026-10-15']));

    $service = app(ReportQueryService::class);
    $request = new ReportRequest(range: ReportRange::explicit('2026-10-01', '2026-11-30'), includePlanned: true);
    $read = fn (string $userId): array => [
        $service->walletSummary($userId, $wallet->id, $request),
        $service->walletByCategory($userId, $wallet->id, $request),
        $service->walletTrend($userId, $wallet->id, new ReportRequest(date: '2026-11-15')),
    ];

    expect($read($partner->id))->toEqual($read($owner->id))
        ->and($read($owner->id)[0]->figures->expenses->amountMinor)->toBe(4000);
});

test('a third person\'s spending in the same platform category in their own wallet is in neither report', function () {
    freezeClock('2026-10-15 10:00:00');
    $owner = person('owner@example.test');
    $partner = person('partner@example.test');
    $stranger = person('stranger@example.test');
    $shared = ownedWallet($owner->id);
    addMember($shared->id, $partner->id, 'editor');
    $groceries = platformCategoryId();
    spend($owner->id, $shared->id, 1000, ['category_id' => $groceries]);
    spend($stranger->id, ownedWallet($stranger->id)->id, 99999, ['category_id' => $groceries]);
    $key = Category::query()->whereKey($groceries)->value('system_key');

    $service = app(ReportQueryService::class);

    foreach ([$owner->id, $partner->id] as $userId) {
        $items = $service->walletByCategory($userId, $shared->id, new ReportRequest)->breakdown->expenses->items;

        expect($items)->toHaveCount(1)->and($items[0]->label)->toBe($key)->and($items[0]->value->amountMinor)->toBe(1000);
    }

    $across = $service->byCategory($partner->id, new ReportRequest)->breakdown->expenses;

    expect($across->total->amountMinor)->toBe(1000);
});
