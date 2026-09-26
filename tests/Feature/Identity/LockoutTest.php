<?php

use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\SignInFailure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
 * Lockout (security baseline; spec 007, US5, FR-040–FR-043): 5 failures lock
 * for 15 minutes, 10 for 60, never longer; an unregistered address locks
 * exactly the same; the origin limit catches spraying across accounts.
 */

function failSignIn(string $email = 'ada@example.test', int $times = 1, string $ip = '198.51.100.7'): void
{
    foreach (range(1, $times) as $attempt) {
        test()->withServerVariables(['REMOTE_ADDR' => $ip]);
        signInAs($email, 'not the password');
    }
}

test('five failures lock for 15 minutes, even against the right password', function (string $email) {
    if ($email === 'ada@example.test') {
        person();
    }

    failSignIn($email, 5);
    $response = signInAs($email, 'correct horse');

    $response->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(899)->toBeLessThanOrEqual(900)
        ->and(SignInFailure::query()->count())->toBe(1);
})->with(['registered' => 'ada@example.test', 'unregistered' => 'nobody@example.test']);

test('the lock ends on its own after 15 minutes', function () {
    person();
    failSignIn(times: 5);

    $this->travel(15)->minutes();
    $this->travel(1)->seconds();

    signInAs()->assertOk();
    expect(SignInFailure::query()->count())->toBe(0);
});

test('ten consecutive failures lock for 60 minutes, and never longer', function () {
    person();
    failSignIn(times: 5);
    $this->travel(16)->minutes();
    failSignIn(times: 5);

    $row = SignInFailure::query()->sole();
    expect($row->consecutive_failures)->toBe(10)
        ->and((int) round($row->last_failed_at->diffInMinutes($row->locked_until)))->toBe(60);

    $this->travel(61)->minutes();
    failSignIn();
    expect(SignInFailure::query()->sole()->last_failed_at->diffInMinutes(SignInFailure::query()->sole()->locked_until))->toBeLessThanOrEqual(60.0);
});

test('a completed reset clears the lock at once', function () {
    Mail::fake();
    $user = person();
    failSignIn(times: 5);

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/reset-password', ['token' => linkFor($user->id, LinkKind::ResetPassword), 'password' => 'a new password'])->assertNoContent();
    $this->flushHeaders();

    signInAs(password: 'a new password')->assertOk();
});

test('a locked attempt costs no hash and does not extend the lock', function () {
    person();
    failSignIn(times: 5);
    $lockedUntil = SignInFailure::query()->sole()->locked_until;
    $this->travel(1)->minutes();

    Hash::spy();
    signInAs()->assertStatus(423);

    Hash::shouldNotHaveReceived('check');
    expect(SignInFailure::query()->sole()->locked_until)->toEqual($lockedUntil);
});

test('twenty failures from one origin across any addresses stop that origin only', function () {
    foreach (range(1, 20) as $n) {
        failSignIn("spray{$n}@example.test", ip: '203.0.113.9');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    signInAs('spray21@example.test', 'whatever')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
    signInAs('spray21@example.test', 'whatever')->assertStatus(401);
});

test('failures and locks are audited; an unknown address names nobody', function () {
    person();
    failSignIn(times: 5);
    failSignIn('nobody@example.test', 5);

    $unknown = AuditEntry::query()->where('event', 'sign_in_failed')->whereNull('subject_id')->get();
    expect(AuditEntry::query()->where('event', 'sign_in_failed')->count())->toBe(10)
        ->and($unknown)->toHaveCount(5)
        ->and($unknown->first()->detail['lookup_prefix'])->toMatch('/^[0-9a-f]{8}$/')
        ->and(AuditEntry::query()->where('event', 'lockout_applied')->count())->toBe(2);
});

test('a successful sign-in resets the counter', function () {
    person();
    failSignIn(times: 4);

    signInAs()->assertOk();

    expect(SignInFailure::query()->count())->toBe(0);
});
