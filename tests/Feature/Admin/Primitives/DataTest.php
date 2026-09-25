<?php

/*
| Data primitives (spec 006, FR-013; contracts/components.md → Data).
*/

$columns = [
    ['key' => 'claim', 'label' => 'Claim'],
    ['key' => 'paid', 'label' => 'Paid', 'align' => 'end'],
    ['key' => 'submitted', 'label' => 'Submitted', 'sortable' => true],
];

it('renders a real table whose sortable header announces its order', function () use ($columns) {
    $html = renderAdmin(<<<'BLADE'
        <x-admin::table :columns="$columns" caption="Claims" :sort="['key' => 'submitted', 'direction' => 'desc']" sort-action="sortBy">
            <x-admin::table.row>
                <x-admin::table.cell :mono="true">CLM-2046</x-admin::table.cell>
                <x-admin::table.cell align="end">EGP 1,290</x-admin::table.cell>
                <x-admin::table.cell>4 min ago</x-admin::table.cell>
            </x-admin::table.row>
        </x-admin::table>
        BLADE, ['columns' => $columns]);

    expect($html)
        ->toContain('<table')
        ->toContain('<caption class="sr-only">Claims</caption>')
        ->toContain('aria-sort="descending"')
        ->toContain('wire:click="sortBy(\'submitted\')"')
        ->toContain('CLM-2046');
});

it('shows the header checkbox as mixed when some rows are selected, and marks selected rows', function () use ($columns) {
    $html = renderAdmin(<<<'BLADE'
        <x-admin::table :columns="$columns" :selectable="true" selection="some">
            <x-admin::table.row :selected="true" select-value="CLM-2045"><x-admin::table.cell>a</x-admin::table.cell></x-admin::table.row>
        </x-admin::table>
        BLADE, ['columns' => $columns]);

    expect($html)
        ->toContain('aria-checked="mixed"')
        ->toContain('aria-label="Select all rows on this page"')
        ->toContain('aria-selected="true"')
        ->toContain('bg-accent-soft');
});

it('shows skeleton rows while loading and never the empty state', function () use ($columns) {
    $html = renderAdmin('<x-admin::table :columns="$columns" :loading="true" :empty="true" empty-title="Queue is clear" />', ['columns' => $columns]);

    expect($html)
        ->toContain('aria-busy="true"')
        ->toContain('data-skeleton-row')
        ->not->toContain('Queue is clear');
});

it('shows the empty state when there are no rows', function () use ($columns) {
    $html = renderAdmin('<x-admin::table :columns="$columns" :empty="true" empty-title="Queue is clear" empty-tone="positive" />', ['columns' => $columns]);

    expect($html)->toContain('Queue is clear')->not->toContain('data-skeleton-row');
});

it('labels each filter chip remove button', function () {
    $html = renderAdmin('<x-admin::table.filters :applied="[[\'key\' => \'status\', \'label\' => \'Status: Pending\']]" remove-action="removeFilter"><x-admin::table.add-filter>Plan</x-admin::table.add-filter></x-admin::table.filters>');

    expect($html)
        ->toContain('aria-label="Remove filter Status: Pending"')
        ->toContain('wire:click="removeFilter(\'status\')"')
        ->toContain('Plan');
});

it('disables newer on the first page of cursor pagination', function () {
    $html = renderAdmin('<x-admin::table.pagination summary="Showing 6" next-url="/admin/claims?cursor=abc" />');

    expect($html)
        ->toContain('<nav aria-label="Pagination"')
        ->toMatch('/<button type="button"\s+disabled[^>]*>.*Newer/s')
        ->toContain('href="/admin/claims?cursor=abc"');
});

it('renders a description list', function () {
    expect(renderAdmin('<x-admin::description-list :items="[[\'term\' => \'Plan\', \'value\' => \'Plus · Yearly\']]" />'))
        ->toContain('<dl')->toContain('<dt')->toContain('Plus · Yearly');
});

it('colours a stat tile delta by its meaning, not its direction', function () {
    $html = renderAdmin('<x-admin::stat-tile label="Churned subscribers" value="146" delta="21% more" delta-meaning="bad" delta-direction="up" />');

    expect($html)
        ->toContain('text-over-text')
        ->toContain('data-icon="trend-up"')
        ->toContain(', unfavourable');
});

it('hides a suppressed stat tile figure behind the anonymity floor', function () {
    $html = renderAdmin('<x-admin::stat-tile label="Lifetime buyers · Assiut" value="7" :suppressed="true" />');

    expect($html)->toContain('Fewer than 10')->not->toContain('>7<');
});

it('shows a loading stat tile as busy', function () {
    expect(renderAdmin('<x-admin::stat-tile label="Revenue" :loading="true" />'))->toContain('aria-busy="true"')->toContain('animate-shimmer');
});

it('states a stat tile ring value in text', function () {
    expect(renderAdmin('<x-admin::stat-tile label="Revenue · September" value="EGP 612,400" :ring="83" ring-label="25/30" />'))
        ->toContain('stroke-dasharray="427 515"')
        ->toContain('25/30');
});

it('renders a titled card as a labelled section', function () {
    $html = renderAdmin('<x-admin::card title="Subscription">body</x-admin::card>');

    preg_match('/aria-labelledby="([^"]+)"/', $html, $labelledBy);

    expect($html)->toContain('<section')->toContain("id=\"{$labelledBy[1]}\"");
});
