<?php

/*
| Forms primitives (spec 006, FR-012; contracts/components.md → Forms).
*/

it('wires a field label, hint and error to its control', function () {
    $html = renderAdmin('<x-admin::input name="reference" label="InstaPay reference" hint="12 characters" error="Reference must be 12 characters." :required="true" />');

    expect($html)
        ->toContain('<label for="reference"')
        ->toContain('id="reference"')
        ->toContain('aria-describedby="reference-error reference-hint"')
        ->toContain('id="reference-error"')
        ->toContain('id="reference-hint"')
        ->toContain('aria-invalid="true"')
        ->toContain('(Required)');
});

it('keeps email, number and money inputs left-to-right', function (string $blade) {
    expect(renderAdmin($blade))->toContain('dir="ltr"');
})->with([
    'email' => '<x-admin::input name="email" type="email" />',
    'number' => '<x-admin::number name="members" />',
    'money' => '<x-admin::money-input name="price" currency="EGP" :value="14900" />',
]);

it('shows a money input in major units and submits minor units', function () {
    $html = renderAdmin('<x-admin::money-input name="price" currency="egp" :value="14900" label="Monthly price" />');

    expect($html)
        ->toContain('value="149.00"')
        ->toMatch('/<input type="hidden" name="price"[^>]*value="14900"/')
        ->toContain('EGP')
        ->toContain('Saved as 14900 minor units');
});

it('counts textarea characters against the limit', function () {
    $html = renderAdmin('<x-admin::textarea name="reason" label="Reason" :max="500" value="Reference verified" />');

    expect($html)
        ->toContain('maxlength="500"')
        ->toContain('aria-describedby="reason-count"')
        ->toContain('aria-live="polite"')
        ->toMatch('/<span x-text="count">18<\/span> \/ 500/');
});

it('renders a select as a combobox over a listbox with a hidden value', function () {
    $html = renderAdmin('<x-admin::select name="plan" label="Plan" value="plus_yearly" :options="$options" />', [
        'options' => [
            ['value' => 'free', 'label' => 'Free', 'meta' => 'EGP 0'],
            ['value' => 'plus_yearly', 'label' => 'Plus · Yearly', 'meta' => 'EGP 1,290'],
        ],
    ]);

    expect($html)
        ->toContain('role="combobox"')
        ->toContain('aria-haspopup="listbox"')
        ->toContain('aria-controls="plan-listbox"')
        ->toContain('role="listbox"')
        ->toMatch('/<input type="hidden" name="plan"[^>]*value="plus_yearly"/')
        ->toMatch('/role="option"\s+aria-selected="true"\s+data-value="plus_yearly"/');
});

it('marks an indeterminate checkbox as mixed', function () {
    expect(renderAdmin('<x-admin::checkbox name="all" label="Select all" :indeterminate="true" />'))
        ->toContain('aria-checked="mixed"');
});

it('renders a toggle as a switch', function (bool $checked) {
    $state = $checked ? 'true' : 'false';

    expect(renderAdmin("<x-admin::toggle name=\"sharing\" label=\"Sharing\" :checked=\"{$state}\" />"))
        ->toContain('role="switch"')
        ->toContain("aria-checked=\"{$state}\"");
})->with([true, false]);

it('groups radios in a fieldset and styles every card from its live checked state', function () {
    $html = renderAdmin('<x-admin::radio-group name="period" legend="Period" value="yearly" :options="[[\'value\' => \'yearly\', \'label\' => \'Yearly\'], [\'value\' => \'monthly\', \'label\' => \'Monthly\']]" />');

    expect($html)->toContain('<fieldset')->toContain('<legend')->toMatch('/value="yearly"\s+checked/')
        ->and(substr_count($html, 'has-checked:bg-accent-soft'))->toBe(2);
});

it('keeps a picked date left-to-right in a dialog picker', function () {
    $html = renderAdmin('<x-admin::date-picker name="starts_on" label="Period start" value="2026-09-25" />');

    expect($html)
        ->toContain('aria-haspopup="dialog"')
        ->toContain('role="grid"')
        ->toContain('<bdi dir="ltr" data-date-value')
        ->toContain('September 2026');
});

it('shows an instant picker in the operator timezone with the stored UTC value', function () {
    $html = renderAdmin('<x-admin::instant-picker name="revoke_at" label="Revoke at" value="2026-09-30T20:59:00Z" timezone="Africa/Cairo" />');

    expect($html)
        ->toContain('name="revoke_at[date]"')
        ->toContain('value="2026-09-30"')
        ->toContain('value="23:59"')
        ->toContain('Africa/Cairo')
        ->toContain('2026-09-30T20:59:00Z');
});

it('marks the search region and its shortcut', function () {
    expect(renderAdmin('<x-admin::search placeholder="Email, user ID or claim ID" />'))
        ->toContain('role="search"')
        ->toContain('aria-keyshortcuts="/"');
});

it('labels a form section by its title', function () {
    $html = renderAdmin('<x-admin::form-section title="Pricing">fields</x-admin::form-section>');

    preg_match('/aria-labelledby="([^"]+)"/', $html, $labelledBy);

    expect($html)->toContain("id=\"{$labelledBy[1]}\"")->toContain('Pricing');
});

it('links each validation error to its field and renders nothing without errors', function () {
    $html = renderAdmin('<x-admin::validation-summary :errors="[\'sku\' => \'Google Play product ID is required\', \'yearly\' => \'Yearly price must be above monthly\']" />');

    expect($html)
        ->toContain('role="alert"')
        ->toContain('2 fields need attention before saving')
        ->toContain('href="#sku"')
        ->and(trim(renderAdmin('<x-admin::validation-summary :errors="[]" />')))->toBe('');
});
