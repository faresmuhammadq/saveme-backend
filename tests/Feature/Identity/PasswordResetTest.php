<?php

use App\Modules\Identity\Application\Jobs\SendResetMail;
use App\Modules\Identity\Application\Mail\ResetPasswordMail;
use App\Modules\Identity\Application\Passwords\RequestReset;
use App\Modules\Identity\Application\Passwords\ResetPassword;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Refusals\ResetLinkInvalid;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\SignInFailure;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Completing a reset (spec 007, FR-031): every session ends, the lock clears,
 * other reset links die, the change is audited, and nobody is signed in.
 */

test('a completed reset ends every session, clears the lock and supersedes other links', function () {
    $user = person();
    signInAs();
    signInAs();
    $other = linkFor($user->id, LinkKind::ResetPassword);
    SignInFailure::query()->forceCreate(['lookup' => app(AddressLookup::class)->for('ada@example.test'), 'consecutive_failures' => 5, 'locked_until' => now()->addMinutes(15), 'last_failed_at' => now()]);

    // The newest link wins; the earlier one was superseded when this was issued.
    $secret = linkFor($user->id, LinkKind::ResetPassword);
    app(ResetPassword::class)->handle($secret, 'a new password');

    expect(DeviceSession::query()->where('state', 'active')->count())->toBe(0)
        ->and(DeviceSession::query()->pluck('end_reason')->unique()->all())->toBe(['password_reset'])
        ->and(SignInFailure::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'password_changed')->count())->toBe(1)
        ->and(AuditEntry::query()->where('event', 'session_revoked')->count())->toBe(2)
        ->and(DeviceSession::query()->count())->toBe(2);

    expect(fn () => app(ResetPassword::class)->handle($other, 'yet another one'))->toThrow(ResetLinkInvalid::class);
});

test('a second request supersedes the first link', function () {
    $user = person();
    $first = linkFor($user->id, LinkKind::ResetPassword);
    linkFor($user->id, LinkKind::ResetPassword);

    expect(fn () => app(ResetPassword::class)->handle($first, 'a new password'))->toThrow(ResetLinkInvalid::class);
});

test('the reset mail goes only to a registered address', function () {
    Mail::fake();
    person();
    $lookup = app(AddressLookup::class);

    app()->call([new SendResetMail($lookup->for('ada@example.test')), 'handle']);
    app()->call([new SendResetMail($lookup->for('nobody@example.test')), 'handle']);

    Mail::assertSent(ResetPasswordMail::class, 1);
    Mail::assertSent(ResetPasswordMail::class, fn (ResetPasswordMail $mail): bool => $mail->hasTo('ada@example.test'));
});

test('a reset request dispatches exactly one job, registered or not', function () {
    Queue::fake();
    person();

    app(RequestReset::class)->handle('ada@example.test');
    app(RequestReset::class)->handle('nobody@example.test');

    Queue::assertPushed(SendResetMail::class, 2);
});
