<?php

/*
| Direction follows the operator's language (spec 006, FR-030, FR-037).
*/

use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', ResolveAdminLocale::class])
        ->get('/_test/admin-direction', fn () => Blade::render('<x-admin::layouts.document title="Direction">page</x-admin::layouts.document>'));
});

it('renders right-to-left in Arabic and left-to-right in English', function (string $locale, string $direction) {
    $this->withSession([ResolveAdminLocale::SESSION_KEY => $locale])
        ->get('/_test/admin-direction')
        ->assertSee("<html lang=\"{$locale}\" dir=\"{$direction}\"", escape: false);
})->with([['ar', 'rtl'], ['en', 'ltr']]);

it('follows the browser language on a first visit', function (string $acceptLanguage, string $expected) {
    $this->withHeader('Accept-Language', $acceptLanguage)
        ->get('/_test/admin-direction')
        ->assertSee("<html lang=\"{$expected}\"", escape: false);
})->with([
    'Arabic (Egypt)' => ['ar-EG,ar;q=0.9,en;q=0.8', 'ar'],
    'English first' => ['en-GB,ar;q=0.5', 'en'],
    'unsupported falls back to English' => ['fr-FR,fr;q=0.9', 'en'],
    'unsupported then Arabic' => ['fr-FR,ar;q=0.8', 'ar'],
]);

it('prefers the session choice over the browser language', function () {
    $this->withSession([ResolveAdminLocale::SESSION_KEY => 'en'])
        ->withHeader('Accept-Language', 'ar-EG')
        ->get('/_test/admin-direction')
        ->assertSee('<html lang="en" dir="ltr"', escape: false);
});

it('ignores an unsupported session value', function () {
    $this->withSession([ResolveAdminLocale::SESSION_KEY => 'fr'])
        ->withHeader('Accept-Language', 'ar')
        ->get('/_test/admin-direction')
        ->assertSee('<html lang="ar" dir="rtl"', escape: false);
});
