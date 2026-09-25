<?php

/*
| Money display (spec 006, FR-020, FR-021; contracts/values-and-rtl.md).
| Integer minor units in, Western numerals out, no floating point on the way.
*/

use App\Modules\Admin\Http\Presenters\MoneyPresenter;

it('formats an amount in each supported currency and language', function (int $minor, string $currency, string $locale, string $expected) {
    expect((new MoneyPresenter)->format($minor, $currency, $locale))->toBe($expected);
})->with([
    'EGP en' => [129000, 'EGP', 'en', 'EGP 1,290.00'],
    'EGP ar drops a zero fraction' => [129000, 'EGP', 'ar', '1,290 ج.م'],
    'EGP ar keeps a non-zero fraction' => [129050, 'EGP', 'ar', '1,290.50 ج.م'],
    'SAR en' => [129000, 'SAR', 'en', 'SAR 1,290.00'],
    'SAR ar' => [129000, 'SAR', 'ar', '1,290 ر.س'],
    'AED ar' => [129000, 'AED', 'ar', '1,290 د.إ'],
    'USD ar' => [129000, 'USD', 'ar', '1,290 US$'],
    'unknown code en' => [129000, 'XYZ', 'en', 'XYZ 1,290.00'],
    'unknown code ar' => [129000, 'XYZ', 'ar', '1,290 XYZ'],
    'zero en' => [0, 'EGP', 'en', 'EGP 0.00'],
    'zero ar' => [0, 'EGP', 'ar', '0 ج.م'],
    'sub-unit en' => [5, 'EGP', 'en', 'EGP 0.05'],
    'negative en' => [-4000, 'EGP', 'en', '−EGP 40.00'],
    'negative ar' => [-4000, 'EGP', 'ar', '−40 ج.م'],
    'millions group every three digits' => [123456789012, 'EGP', 'en', 'EGP 1,234,567,890.12'],
]);

it('keeps every digit of amounts a float cannot hold exactly', function () {
    $presenter = new MoneyPresenter;

    expect($presenter->format(900719925474099312, 'EGP', 'en'))->toBe('EGP 9,007,199,254,740,993.12')
        ->and($presenter->format(PHP_INT_MAX - 1, 'EGP', 'en'))->toBe('EGP 92,233,720,368,547,758.06')
        ->and($presenter->format(PHP_INT_MIN, 'EGP', 'en'))->toBe('−EGP 92,233,720,368,547,758.08');
});

it('shortens aggregate figures by truncating, never rounding', function (int $minor, string $locale, string $expected) {
    expect((new MoneyPresenter)->compact($minor, 'EGP', $locale))->toBe($expected);
})->with([
    'below a thousand' => [99900, 'en', 'EGP 999'],
    'thousands' => [129900, 'en', 'EGP 1.2K'],
    'exact million drops .0' => [100000000, 'en', 'EGP 1M'],
    'millions' => [3829900000, 'en', 'EGP 38.2M'],
    'billions' => [120000000000, 'en', 'EGP 1.2B'],
    'millions ar' => [3820000000, 'ar', '38.2 مليون ج.م'],
    'thousands ar' => [120000, 'ar', '1.2 ألف ج.م'],
    'billions ar' => [120000000000, 'ar', '1.2 مليار ج.م'],
    'negative' => [-129900, 'en', '−EGP 1.2K'],
]);

it('never emits Eastern Arabic-Indic digits', function () {
    $presenter = new MoneyPresenter;

    expect($presenter->format(987654321, 'EGP', 'ar').$presenter->compact(987654321, 'SAR', 'ar'))
        ->toUseWesternDigitsOnly();
});

it('describes the stored value for markup', function () {
    expect((new MoneyPresenter)->machineValue(129000, 'egp'))->toBe('129000 EGP');
});

it('gives a money input its editable text without grouping', function () {
    $presenter = new MoneyPresenter;

    expect($presenter->plain(129000, 'EGP'))->toBe('1290.00')
        ->and($presenter->plain(-5, 'EGP'))->toBe('-0.05');
});
