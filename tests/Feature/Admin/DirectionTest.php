<?php

/*
| Direction follows the operator's language (spec 006, FR-030, FR-037).
*/

use App\Modules\Admin\Domain\SecondFactor\Base32;
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

describe('sign-in and set-up screens', function () {
    it('render right-to-left in Arabic with codes, keys and emails kept left-to-right', function () {
        ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();
        $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar']);

        $this->get(route('admin.sign-in'))->assertSee('<html lang="ar" dir="rtl"', escape: false)->assertSee('سجّل الدخول إلى لوحة التشغيل');

        $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
        $second = $this->get(route('admin.sign-in.code'))->assertOk()->assertSee('dir="rtl"', escape: false);

        expect($second->getContent())->toContain('<bdi dir="ltr" class="font-semibold text-ink">'.$email.'</bdi>')
            ->and($second->getContent())->toContain('dir="ltr"');
        expect($second->getContent())->toUseWesternDigitsOnly();
    });

    it('wrap the setup key, the setup link and the recovery codes in left-to-right isolation', function () {
        $made = makeOperator('awaiting_password_change', ['password' => 'TEMP-PASSWORD-2026']);
        $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar']);
        $this->post(route('admin.sign-in.submit'), ['email' => $made['email'], 'password' => 'TEMP-PASSWORD-2026']);
        $this->post(route('admin.password.new.submit'), ['password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase']);

        $enrol = $this->get(route('admin.enrol'))->assertOk()->getContent();
        expect($enrol)->toMatch('/<bdi dir="ltr"[^>]*data-test="enrol-key"/')
            ->and($enrol)->toContain('data-test="enrol-link"')
            ->and($enrol)->toMatch('/data-test="enrol-link"/')
            ->and($enrol)->toContain('dir="rtl"')
            ->and($enrol)->toUseWesternDigitsOnly();

        preg_match('/data-test="enrol-key"[^>]*>([^<]+)</', $enrol, $key);
        $secret = Base32::decode(str_replace(' ', '', trim($key[1])));
        $codes = $this->post(route('admin.enrol.submit'), ['one_time_code' => currentCode((string) $secret)])->assertOk()->getContent();

        expect($codes)->toContain('data-test="recovery-code-list" dir="ltr"')
            ->and($codes)->toContain('dir="rtl"')
            ->and($codes)->toUseWesternDigitsOnly();
    });

    it('render the security page and the requests page right-to-left', function () {
        ['operator' => $operator] = makeOperator();
        signInOperator($operator);
        $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar']);

        foreach (['/admin', '/admin/security', '/admin/reenrolment-requests'] as $path) {
            $html = $this->get($path)->assertOk()->assertSee('<html lang="ar" dir="rtl"', escape: false)->getContent();
            expect($html)->toUseWesternDigitsOnly();
        }

        $this->get('/admin/security')->assertSee('الأمان')->assertSee('تغيير كلمة المرور');
    });
});
