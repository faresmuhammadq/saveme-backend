<?php

/*
| Value display primitives (spec 006, FR-016, FR-020–FR-024, FR-032, FR-033).
*/

use Carbon\CarbonImmutable;

it('renders money with its stored value and the number kept left-to-right', function () {
    expect(renderAdmin('<x-admin::money :minor="129000" currency="EGP" />'))
        ->toContain('<data value="129000 EGP"')
        ->toContain('<bdi dir="ltr">EGP 1,290.00</bdi>');
});

it('keeps the full value in the title and accessible name of compact money', function () {
    expect(renderAdmin('<x-admin::money :minor="3820000000" currency="EGP" :compact="true" />'))
        ->toContain('title="EGP 38,200,000.00"')
        ->toContain('aria-label="EGP 38,200,000.00"')
        ->toContain('EGP 38.2M');
});

it('marks calendar dates and instants as machine-readable times', function () {
    expect(renderAdmin('<x-admin::date value="2026-09-25" />'))->toContain('<time datetime="2026-09-25"')
        ->and(renderAdmin('<x-admin::instant value="2026-09-25T18:04:00Z" timezone="Africa/Cairo" />'))
        ->toContain('datetime="2026-09-25T18:04:00Z"')
        ->toContain('25 Sep 2026, 21:04 EEST');
});

it('shows relative time with the absolute instant in its title', function () {
    CarbonImmutable::setTestNow('2026-09-25T18:08:00Z');

    expect(renderAdmin('<x-admin::relative-time value="2026-09-25T18:04:00Z" timezone="Africa/Cairo" />'))
        ->toContain('>4 minutes ago</time>')
        ->toContain('title="25 Sep 2026, 21:04 EEST"');
});

it('truncates identifiers in the middle and copies the full value', function () {
    $html = renderAdmin('<x-admin::identifier value="0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3" label="user ID" />');

    expect($html)
        ->toContain('<bdi dir="ltr" title="0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3"')
        ->toContain('0192f4…f2b6d3')
        ->toContain("adminCopy('0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3')")
        ->toContain('aria-label="Copy user ID"');
});

it('mirrors only direction-bearing icons in right-to-left', function () {
    expect(renderAdmin('<x-admin::icon name="chevron-end" />'))->toContain('rtl:-scale-x-100')
        ->and(renderAdmin('<x-admin::icon name="search" />'))->not->toContain('rtl:-scale-x-100');
});

it('gives a labelled icon an image role and hides a decorative one', function () {
    expect(renderAdmin('<x-admin::icon name="lock" label="Locked" />'))->toContain('role="img" aria-label="Locked"')
        ->and(renderAdmin('<x-admin::icon name="lock" />'))->toContain('aria-hidden="true"');
});

it('keeps only the number left-to-right in Arabic money, so the symbol reads in Arabic order', function () {
    expect(renderAdmin('<x-admin::money :minor="129000" currency="EGP" />', locale: 'ar'))
        ->toContain('<bdi dir="ltr">1,290</bdi> ج.م')
        ->and(renderAdmin('<x-admin::money :minor="3820000000" currency="EGP" :compact="true" />', locale: 'ar'))
        ->toContain('<bdi dir="ltr">38.2</bdi> مليون ج.م');
});

it('isolates left-to-right values inside Arabic text', function (string $blade, string $isolated) {
    expect(renderAdmin($blade, locale: 'ar'))->toContain($isolated);
})->with([
    'identifier' => ['<x-admin::identifier value="0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3" />', '<bdi dir="ltr" title="0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3"'],
    'calendar date' => ['<x-admin::date value="2026-09-25" />', '<bdi dir="ltr">2026-09-25</bdi>'],
    'email input' => ['<x-admin::input name="email" type="email" value="omar.farouk@example.com" />', 'dir="ltr"'],
    'picked date' => ['<x-admin::date-picker name="starts_on" value="2026-09-25" />', '<bdi dir="ltr" data-date-value'],
    'reason attribution code' => ['<x-admin::reason-prompt action-key="fixture.approve" attribution="مريم س." />', '<bdi dir="ltr" class="font-mono">admin_action.fixture.approve</bdi>'],
]);

it('shows Arabic instants and dates with Western digits only', function () {
    CarbonImmutable::setTestNow('2026-09-25T20:00:00Z');

    $html = renderAdmin(<<<'BLADE'
        <x-admin::money :minor="987654321" currency="EGP" />
        <x-admin::instant value="2026-09-25T18:04:00Z" timezone="Africa/Cairo" />
        <x-admin::relative-time value="2026-09-25T18:04:00Z" timezone="Africa/Cairo" />
        <x-admin::date-picker name="starts_on" value="2026-09-25" />
        <x-admin::stat-tile label="المشتركون" value="6,140" :ring="83" ring-label="25/30" />
        <x-admin::pagination :current="2" :last="9" url="/admin?page=:page" />
        <x-admin::textarea name="reason" :max="500" />
        BLADE, locale: 'ar');

    expect($html)
        ->toContain('25 سبتمبر 2026، 21:04 EEST')
        ->toContain('سبتمبر 2026')
        ->toUseWesternDigitsOnly();
});
