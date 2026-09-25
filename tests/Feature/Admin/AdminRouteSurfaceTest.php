<?php

/*
| Until operator sign-in adds the admin guard (brief D2), the local gallery is
| the only route under /admin (spec 006, FR-051; constitution P-18 rule 4).
*/

it('registers only the gallery under /admin in the local environment', function () {
    registerAdminRoutesAs('local');

    expect(adminRouteUris())->toBe(['admin/_gallery'])
        ->and(route('admin.gallery', absolute: false))->toBe('/admin/_gallery');
});

it('registers nothing under /admin outside the local environment', function (string $environment) {
    registerAdminRoutesAs($environment);

    expect(adminRouteUris())->toBe([]);
})->with(['production', 'staging']);
