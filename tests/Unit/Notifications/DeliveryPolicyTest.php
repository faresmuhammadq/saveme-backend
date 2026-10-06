<?php

use App\Modules\Notifications\Domain\Delivery\DeliveryPolicy;
use App\Modules\Notifications\Domain\Delivery\SkipReason;
use App\Modules\Notifications\Domain\Preferences\Category;
use App\Modules\Notifications\Domain\Preferences\Preferences;
use App\Modules\Notifications\Domain\Preferences\QuietHours;

function policyPreferences(array $off = [], bool $quiet = true): Preferences
{
    $categories = array_fill_keys(array_map(fn (Category $c): string => $c->value, Category::cases()), true);

    foreach ($off as $category) {
        $categories[$category->value] = false;
    }

    return new Preferences(2, $categories, new QuietHours($quiet, 22 * 60, 8 * 60), null);
}

function decide(Preferences $preferences, string $at = '2026-10-06 10:00:00', bool $active = true, int $hour = 0, int $day = 0, Category $category = Category::Sharing)
{
    return (new DeliveryPolicy(10, 30))->decide($preferences, 'Africa/Cairo', $active, $category, new DateTimeImmutable($at, new DateTimeZone('UTC')), $hour, $day);
}

test('a notification in the daytime to an active account is sent', function () {
    expect(decide(policyPreferences())->isSend())->toBeTrue();
});

test('an account that is leaving for good is skipped before anything else', function () {
    $decision = decide(policyPreferences([Category::Sharing]), active: false);

    expect($decision->isSkip())->toBeTrue()->and($decision->reason)->toBe(SkipReason::InactiveAccount);
});

test('a category turned off is skipped', function () {
    $decision = decide(policyPreferences([Category::Sharing]));

    expect($decision->isSkip())->toBeTrue()->and($decision->reason)->toBe(SkipReason::CategoryOff);
});

test('inside quiet hours a notification is held until they end', function () {
    $decision = decide(policyPreferences(), '2026-10-06 20:30:00'); // 23:30 in Cairo

    expect($decision->isHold())->toBeTrue()
        ->and($decision->until->format('Y-m-d H:i'))->toBe('2026-10-07 05:00');
});

test('quiet hours turned off hold nothing', function () {
    expect(decide(policyPreferences(quiet: false), '2026-10-06 20:30:00')->isSend())->toBeTrue();
});

test('the hourly and daily rate limits skip, and one below them does not', function () {
    expect(decide(policyPreferences(), hour: 9)->isSend())->toBeTrue()
        ->and(decide(policyPreferences(), hour: 10)->reason)->toBe(SkipReason::RateLimited)
        ->and(decide(policyPreferences(), day: 29)->isSend())->toBeTrue()
        ->and(decide(policyPreferences(), day: 30)->reason)->toBe(SkipReason::RateLimited);
});
