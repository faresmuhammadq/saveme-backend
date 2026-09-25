<?php

/*
| Calendar dates, instants and relative time (spec 006, FR-022, FR-023, P-12).
*/

use App\Modules\Admin\Http\Presenters\TemporalPresenter;
use Carbon\CarbonImmutable;

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('shows a calendar date exactly as stored, whatever the default timezone', function () {
    date_default_timezone_set('Pacific/Kiritimati');

    expect((new TemporalPresenter)->calendarDate('2026-09-25'))->toBe('2026-09-25');
});

it('rejects a calendar date that is not YYYY-MM-DD', function () {
    (new TemporalPresenter)->calendarDate('25/09/2026');
})->throws(InvalidArgumentException::class);

it('shows an instant in the operator timezone with its zone', function (string $locale, string $expected) {
    expect((new TemporalPresenter)->instant('2026-09-25T18:04:00Z', 'Africa/Cairo', $locale))->toBe($expected);
})->with([
    'en' => ['en', '25 Sep 2026, 21:04 EEST'],
    'ar' => ['ar', '25 سبتمبر 2026، 21:04 EEST'],
]);

it('follows the daylight-saving change in the zone abbreviation', function () {
    expect((new TemporalPresenter)->instant('2026-12-01T10:00:00Z', 'Africa/Cairo', 'en'))->toBe('1 Dec 2026, 12:00 EET');
});

it('shows recent instants relatively', function (string $locale, string $expected) {
    $now = CarbonImmutable::parse('2026-09-25T18:04:00Z');

    expect((new TemporalPresenter)->relative('2026-09-25T18:00:00Z', $now, 'Africa/Cairo', $locale))->toBe($expected);
})->with([
    'en' => ['en', '4 minutes ago'],
    'ar' => ['ar', 'منذ 4 دقائق'],
]);

it('falls back to the absolute instant at seven days and older', function () {
    $now = CarbonImmutable::parse('2026-10-02T18:04:00Z');

    expect((new TemporalPresenter)->relative('2026-09-25T18:04:00Z', $now, 'Africa/Cairo', 'en'))->toBe('25 Sep 2026, 21:04 EEST');
});

it('never emits Eastern Arabic-Indic digits', function () {
    $presenter = new TemporalPresenter;
    $now = CarbonImmutable::parse('2026-09-25T20:00:00Z');

    expect($presenter->instant('2026-09-25T18:04:00Z', 'Africa/Cairo', 'ar').$presenter->relative('2026-09-25T18:04:00Z', $now, 'Africa/Cairo', 'ar'))
        ->toUseWesternDigitsOnly();
});
