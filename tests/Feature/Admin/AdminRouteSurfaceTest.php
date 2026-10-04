<?php

/*
| The back-office route surface (spec 018, FR-070, FR-073; contracts/
| routes-and-screens.md; constitution P-18 rule 4). Every route is either a
| step of sign-in, the local gallery, or behind EnsureOperatorSignedIn.
| Updating this list is deliberate: a route added to /admin changes it.
*/

use App\Modules\Admin\Http\Middleware\BackOfficeHeaders;
use App\Modules\Admin\Http\Middleware\EnsureOperatorSignedIn;
use Illuminate\Routing\Route;

const SIGN_IN_STEPS = [
    'admin/sign-in',
    'admin/sign-in/code',
    'admin/sign-in/recovery',
    'admin/sign-in/restart',
    'admin/sign-in/lost-device',
    'admin/password/new',
    'admin/enrol',
    'admin/enrol/done',
];

const GUARDED_SURFACE = [
    'admin',
    'admin/security',
    'admin/security/authenticator',
    'admin/reenrolment-requests',
    'admin/claims/{claim}/receipt',
    'admin/sign-out',
];

/**
 * @return list<Route>
 */
function adminRoutes(): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getRoutes(),
        fn (Route $route): bool => $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'),
    ));
}

function uniqueAdminUris(): array
{
    $uris = array_values(array_unique(adminRouteUris()));
    sort($uris);

    return $uris;
}

function sortedUris(array $uris): array
{
    sort($uris);

    return $uris;
}

it('registers exactly the contracted surface under /admin in the local environment', function () {
    registerAdminRoutesAs('local');

    expect(uniqueAdminUris())->toBe(sortedUris([...SIGN_IN_STEPS, ...GUARDED_SURFACE, 'admin/_gallery']))
        ->and(route('admin.gallery', absolute: false))->toBe('/admin/_gallery')
        ->and(route('admin.overview', absolute: false))->toBe('/admin')
        ->and(route('admin.sign-in', absolute: false))->toBe('/admin/sign-in');
});

it('registers the same surface without the gallery outside the local environment', function (string $environment) {
    registerAdminRoutesAs($environment);

    expect(uniqueAdminUris())->toBe(sortedUris([...SIGN_IN_STEPS, ...GUARDED_SURFACE]));
})->with(['production', 'staging']);

it('puts every route except the sign-in steps and the local gallery behind the operator guard', function () {
    registerAdminRoutesAs('local');

    foreach (adminRoutes() as $route) {
        $guarded = in_array(EnsureOperatorSignedIn::class, $route->gatherMiddleware(), true);
        $exempt = in_array($route->uri(), [...SIGN_IN_STEPS, 'admin/_gallery'], true);

        expect($guarded)->toBe(! $exempt, "{$route->uri()} should ".($exempt ? 'not ' : '').'carry EnsureOperatorSignedIn');
    }
});

it('forbids caching and framing on every back-office route', function () {
    registerAdminRoutesAs('local');

    foreach (adminRoutes() as $route) {
        expect(in_array(BackOfficeHeaders::class, $route->gatherMiddleware(), true))->toBeTrue("{$route->uri()} should carry BackOfficeHeaders");
    }
});

it('sends the headers on a guest page and a guarded page', function () {
    ['operator' => $operator] = makeOperator();

    $guest = $this->get('/admin/sign-in');
    signInOperator($operator);
    $guarded = $this->get('/admin');

    foreach ([$guest, $guarded] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
});

it('keeps the gallery reachable signed out in the local environment', function () {
    registerAdminRoutesAs('local');

    $this->get('/admin/_gallery')->assertOk();
});
