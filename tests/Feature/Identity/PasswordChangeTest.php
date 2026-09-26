<?php

use App\Modules\Identity\Application\Mail\PasswordChangedMail;
use App\Modules\Identity\Application\Passwords\ResetPassword;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Events\DeviceSessionEnded;
use App\Modules\Identity\Domain\Refusals\ResetLinkInvalid;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\SignInFailure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * Changing the password signs everything else out and keeps the device in
 * hand (security baseline A4; spec 007, FR-035, FR-036).
 */

beforeEach(function () {
    Mail::fake();
    $this->user = person();
    $this->a = signInAs(device: deviceBody(['name' => 'A']))->json('data');
    $this->b = signInAs(device: deviceBody(['name' => 'B']))->json('data');
});

test('other sessions end; the one that changed stays; reset links die', function () {
    $link = linkFor($this->user->id, LinkKind::ResetPassword);

    changePassword($this->a['credential'], ['current_password' => 'correct horse', 'new_password' => 'battery staple'])->assertNoContent();

    $this->withHeaders(asCredential($this->b['credential']))->get('/api/v1/auth/sessions')->assertStatus(401);
    $this->flushHeaders();
    $this->withHeaders(asCredential($this->a['credential']))->get('/api/v1/auth/sessions')->assertOk();
    expect(DeviceSession::query()->find($this->b['session']['id'])->end_reason)->toBe('password_changed');
    expect(fn () => app(ResetPassword::class)->handle($link, 'whatever else'))->toThrow(ResetLinkInvalid::class);
});

test('the old password stops working and the new one works', function () {
    changePassword($this->a['credential'], ['current_password' => 'correct horse', 'new_password' => 'battery staple']);

    signInAs(password: 'correct horse')->assertStatus(401);
    signInAs(password: 'battery staple')->assertOk();
});

test('it is audited, announced per ended session, and mailed once', function () {
    Event::fake([DeviceSessionEnded::class]);

    changePassword($this->a['credential'], ['current_password' => 'correct horse', 'new_password' => 'battery staple']);

    expect(AuditEntry::query()->where('event', 'password_changed')->sole()->detail)->toBe(['via' => 'change'])
        ->and(AuditEntry::query()->where('event', 'session_revoked')->where('subject_id', $this->b['session']['id'])->count())->toBe(1);
    Event::assertDispatchedTimes(DeviceSessionEnded::class, 1);
    Mail::assertQueued(PasswordChangedMail::class, 1);
});

test('a wrong current password counts toward the lock', function () {
    changePassword($this->a['credential'], ['current_password' => 'nope', 'new_password' => 'battery staple']);

    expect(SignInFailure::query()->sole()->consecutive_failures)->toBe(1);
});
