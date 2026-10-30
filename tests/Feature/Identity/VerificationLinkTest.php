<?php

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Jobs\SendRegistrationMail;
use App\Modules\Identity\Application\Mail\VerifyAddressMail;
use App\Modules\Identity\Application\Verification\ResendVerification;
use App\Modules\Identity\Application\Verification\VerifyAddress;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Domain\Refusals\VerificationLinkInvalid;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\IdentityLink;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Mail;

/*
 * Verification links: single use, 24 hours, newest only, stored as a hash
 * (security baseline, Reset and verification tokens; spec 007, FR-010–FR-012).
 */

beforeEach(fn () => Mail::fake());

test('the welcome mail carries a 256-bit secret; only its hash is stored', function () {
    $user = person(verified: false);

    (new SendRegistrationMail($user->id, 'en'))->handle(app(Accounts::class), app(LinkRepository::class));

    Mail::assertSent(VerifyAddressMail::class, function (VerifyAddressMail $mail) use ($user): bool {
        expect($mail->secret)->toMatch('/^[A-Za-z0-9_-]{43}$/')
            ->and(IdentityLink::query()->where('user_id', $user->id)->sole()->secret_hash)->toBe(hash('sha256', $mail->secret));

        return $mail->hasTo('ada@example.test');
    });
});

test('a welcome mail reaching the worker after a resend leaves the newer link valid', function () {
    $user = person(verified: false);
    $newer = linkFor($user->id, LinkKind::VerifyAddress);

    (new SendRegistrationMail($user->id, 'en'))->handle(app(Accounts::class), app(LinkRepository::class));

    app(VerifyAddress::class)->handle($newer);

    Mail::assertNothingSent();
    expect(User::query()->find($user->id)->email_verified_at)->not->toBeNull();
});

test('a redelivered welcome mail job issues no second link and sends nothing twice', function () {
    $user = person(verified: false);
    $job = new SendRegistrationMail($user->id, 'en');

    $job->handle(app(Accounts::class), app(LinkRepository::class));
    $job->handle(app(Accounts::class), app(LinkRepository::class));

    Mail::assertSentCount(1);
    expect(IdentityLink::query()->where('user_id', $user->id)->whereNull('superseded_at')->count())->toBe(1);
});

test('a welcome mail whose send fails leaves no link, so the retry starts clean', function () {
    $user = person(verified: false);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    expect(fn () => (new SendRegistrationMail($user->id, 'en'))->handle(app(Accounts::class), app(LinkRepository::class)))
        ->toThrow(RuntimeException::class)
        ->and(IdentityLink::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('asking again supersedes the earlier link', function () {
    $user = person(verified: false);
    $first = linkFor($user->id, LinkKind::VerifyAddress);

    app(ResendVerification::class)->handle($user->id);

    expect(fn () => app(VerifyAddress::class)->handle($first))->toThrow(VerificationLinkInvalid::class);
    Mail::assertQueued(VerifyAddressMail::class);
});

test('an already-verified address gets no mail from a resend', function () {
    $user = person();

    app(ResendVerification::class)->handle($user->id);

    Mail::assertNothingQueued();
});

test('a link redeems exactly once', function () {
    $user = person(verified: false);
    $secret = linkFor($user->id, LinkKind::VerifyAddress);
    $links = app(LinkRepository::class);

    expect($links->redeem($secret, LinkKind::VerifyAddress))->toBe($user->id)
        ->and($links->redeem($secret, LinkKind::VerifyAddress))->toBeNull();
});

test('verifying records address_verified once and never moves the date', function () {
    $user = person(verified: false);

    app(VerifyAddress::class)->handle(linkFor($user->id, LinkKind::VerifyAddress));
    $verifiedAt = User::query()->find($user->id)->email_verified_at;
    $this->travel(1)->hours();
    app(VerifyAddress::class)->handle(linkFor($user->id, LinkKind::VerifyAddress));

    expect(User::query()->find($user->id)->email_verified_at)->toEqual($verifiedAt)
        ->and(AuditEntry::query()->where('event', 'address_verified')->count())->toBe(1);
});

test('a reset link cannot verify an address', function () {
    $user = person(verified: false);

    expect(fn () => app(VerifyAddress::class)->handle(linkFor($user->id, LinkKind::ResetPassword)))->toThrow(VerificationLinkInvalid::class);
});

test('opening the mailed link in a browser verifies the address and points back to the app', function () {
    $user = person(verified: false);
    $secret = linkFor($user->id, LinkKind::VerifyAddress);

    $this->get('/verify-email?token='.$secret)
        ->assertOk()
        ->assertSee(config('identity.links.app_url'), false);

    expect(User::query()->find($user->id)->email_verified_at)->not->toBeNull();
});

test('opening a used or unknown link shows the invalid page and verifies nobody', function () {
    $user = person(verified: false);
    $secret = linkFor($user->id, LinkKind::VerifyAddress);
    app(VerifyAddress::class)->handle($secret);

    $this->get('/verify-email?token='.$secret)->assertStatus(410);
    $this->get('/verify-email?token=nope')->assertStatus(410);
    $this->get('/verify-email')->assertStatus(410);
});
