<?php

/*
| Navigation primitives (spec 006, FR-015; contracts/components.md → Navigation).
*/

it('renders in-page tabs as a tablist with the current tab selected', function (string $style) {
    $html = renderAdmin("<x-admin::tabs style=\"{$style}\" current=\"details\" :items=\"\$items\" label=\"Claim\" />", [
        'items' => [
            ['key' => 'details', 'label' => 'Details', 'wire' => 'show'],
            ['key' => 'receipt', 'label' => 'Receipt', 'count' => 1, 'wire' => 'show'],
        ],
    ]);

    expect($html)
        ->toContain('role="tablist"')
        ->toMatch('/role="tab"\s+aria-selected="true"\s+tabindex="0"\s+wire:click="show\(\'details\'\)"/')
        ->toMatch('/role="tab"\s+aria-selected="false"\s+tabindex="-1"/');
})->with(['underline', 'pill']);

it('renders routed tabs as navigation links marking the current page', function () {
    $html = renderAdmin('<x-admin::tabs current="pending" label="Claim status" :items="$items" />', [
        'items' => [
            ['key' => 'pending', 'label' => 'Pending', 'count' => 38, 'href' => '/admin/claims'],
            ['key' => 'approved', 'label' => 'Approved', 'href' => '/admin/claims/approved'],
        ],
    ]);

    expect($html)
        ->toMatch('/<nav\s+aria-label="Claim status"/')
        ->toMatch('/href="\/admin\/claims"\s+aria-current="page"/')
        ->not->toContain('role="tablist"');
});

it('marks the last breadcrumb as the current page', function () {
    $html = renderAdmin('<x-admin::breadcrumbs :items="[[\'label\' => \'Billing\', \'href\' => \'/admin/billing\'], [\'label\' => \'CLM-2046\']]" />');

    expect($html)
        ->toContain('<nav aria-label="Breadcrumb"')
        ->toContain('<span aria-current="page" class="font-bold text-ink">CLM-2046</span>')
        ->toContain('data-icon="chevron-end"');
});

it('numbers pages around the current one with gaps', function () {
    $html = renderAdmin('<x-admin::pagination :current="2" :last="9" url="/admin/plans?page=:page" />');

    expect($html)
        ->toMatch('/href="\/admin\/plans\?page=2" aria-current="page"/')
        ->toContain('href="/admin/plans?page=9"')
        ->toContain('…')
        ->toContain('aria-label="Previous page"')
        ->toContain('aria-label="Next page"');
});
