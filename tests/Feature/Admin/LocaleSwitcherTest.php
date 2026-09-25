<?php

/*
| The operator's language switch (spec 006, FR-037).
*/

use App\Modules\Admin\Http\Livewire\LocaleSwitcher;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('stores the chosen language for the session and reloads the page', function () {
    Livewire::test(LocaleSwitcher::class)
        ->call('switchTo', 'ar')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(session(ResolveAdminLocale::SESSION_KEY))->toBe('ar');
});

it('refuses a language the back office does not offer', function () {
    Livewire::test(LocaleSwitcher::class)
        ->call('switchTo', 'fr')
        ->assertHasErrors('locale')
        ->assertNoRedirect();

    expect(session(ResolveAdminLocale::SESSION_KEY))->toBeNull();
});

it('names each language in its own language and marks the current one', function () {
    app()->setLocale('ar');

    Livewire::test(LocaleSwitcher::class)
        ->assertSeeHtml('lang="en"')
        ->assertSee('English')
        ->assertSee('العربية')
        ->assertSeeHtml('aria-pressed="true"');
});

it('does not let the browser change where it redirects', function () {
    Livewire::test(LocaleSwitcher::class)->set('returnTo', 'https://evil.example');
})->throws(CannotUpdateLockedPropertyException::class);
