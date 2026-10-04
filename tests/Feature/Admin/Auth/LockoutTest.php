<?php

/*
| Lockout and throttling for operators, separate from the application's
| (spec 018, US7; FR-050 to FR-053; security baseline, Lockout).
*/

use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use App\Modules\Admin\Infrastructure\Models\OperatorSignInFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

function failPassword(string $email, int $times = 1): void
{
    foreach (range(1, $times) as $ignored) {
        test()->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => 'definitely not it']);
    }
}

it('locks an email for 15 minutes after 5 consecutive failures of any kind, even for the right password', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password] = makeOperator();
    giveRecoveryCodes($operator);

    failPassword($email, 2);
    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in.code'));
    $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => '000000']);
    $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => '111111']);
    $this->post(route('admin.sign-in.recovery.submit'), ['recovery_code' => 'AAAAA-AAAAA']);

    app(PendingSignIn::class)->forget();
    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('Too many attempts. Try again in 15 minutes.');

    expect(DB::table('audit_entries')->where('event', 'lockout_applied')->count())->toBe(1)
        ->and(app(PendingSignIn::class)->exists())->toBeFalse();
});

it('lets the right password in again once the lock has passed', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    failPassword($email, 5);
    $this->travel(16)->minutes();

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in.code'));
});

it('locks for 60 minutes after 10 failures and never for longer', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    failPassword($email, 5);
    $this->travel(16)->minutes();
    failPassword($email, 5);

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $this->get(route('admin.sign-in'))->assertSee('Too many attempts. Try again in 60 minutes.');

    $this->travel(61)->minutes();
    failPassword($email, 1);
    $this->travel(61)->minutes();

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in.code'));
    expect(DB::table('audit_entries')->where('event', 'lockout_applied')->get()->map(fn ($e) => json_decode($e->detail, true)['minutes'])->all())->toBe([15, 60, 60]);
});

it('locks an unknown email in exactly the same way and records no readable email', function () {
    failPassword('ghost@example.test', 5);
    $this->post(route('admin.sign-in.submit'), ['email' => 'ghost@example.test', 'password' => 'whatever password'])->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('Too many attempts. Try again in 15 minutes.');

    $entries = DB::table('audit_entries')->whereIn('event', ['sign_in_failed', 'lockout_applied'])->get();
    $lock = $entries->firstWhere('event', 'lockout_applied');

    expect($entries)->toHaveCount(6)
        ->and($lock->subject_id)->toBeNull()
        ->and(json_decode($lock->detail, true))->toHaveKey('lookup_prefix')
        ->and(strlen(json_decode($lock->detail, true)['lookup_prefix']))->toBe(8)
        ->and(json_encode($entries))->not->toContain('ghost@example.test');
});

it('refuses an origin after 20 failures across emails, for 15 minutes', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    foreach (range(1, 20) as $number) {
        failPassword("stuffing{$number}@example.test");
    }

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('Too many attempts. Try again in');

    $this->travel(16)->minutes();
    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in.code'));
});

it('resets the account counter on a full sign-in', function () {
    ['operator' => $operator, 'email' => $email, 'password' => $password, 'secret' => $secret] = makeOperator();

    failPassword($email, 3);
    expect(OperatorSignInFailure::query()->count())->toBe(1);

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => currentCode($secret)])->assertRedirect(route('admin.overview'));
    expect(OperatorSignInFailure::query()->count())->toBe(0);

    app(OperatorSession::class)->end();
    failPassword($email, 4);
    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in.code'));
});

it('discards the half-finished sign-in after 5 wrong codes', function () {
    ['email' => $email, 'password' => $password] = makeOperator();

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password]);
    foreach (range(1, 4) as $ignored) {
        $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => '000000'])->assertRedirect(route('admin.sign-in.code'));
    }
    $this->post(route('admin.sign-in.code.submit'), ['one_time_code' => '000000'])->assertRedirect(route('admin.sign-in'));

    expect(app(PendingSignIn::class)->exists())->toBeFalse();
    $this->get(route('admin.sign-in'))->assertSee('Too many wrong codes. Start again.');
});

it('keeps operator and application counters apart', function () {
    $user = person('shared@example.test', 'correct horse');
    ['email' => $email, 'password' => $password] = makeOperator('enrolled', ['email' => 'shared@example.test']);

    foreach (range(1, 5) as $ignored) {
        signInAs('shared@example.test', 'wrong horse battery');
    }
    expect(DB::table('sign_in_failures')->count())->toBe(1);

    $this->post(route('admin.sign-in.submit'), ['email' => $email, 'password' => $password])->assertRedirect(route('admin.sign-in.code'));
    app(PendingSignIn::class)->forget();

    failPassword($email, 5);
    expect(DB::table('sign_in_failures')->count())->toBe(1)
        ->and(RateLimiter::attempts('admin-origin:127.0.0.0/24'))->toBe(5)
        ->and(OperatorSignInFailure::query()->count())->toBe(1)
        ->and(signInAs('shared@example.test', 'correct horse')->status())->not->toBe(429);
    expect($user)->not->toBeNull();
});

it('writes one lockout entry per lock begun, and none while locked', function () {
    ['email' => $email] = makeOperator();

    failPassword($email, 5);
    failPassword($email, 3);

    expect(DB::table('audit_entries')->where('event', 'lockout_applied')->count())->toBe(1)
        ->and(DB::table('audit_entries')->where('event', 'sign_in_failed')->count())->toBe(5);
});
