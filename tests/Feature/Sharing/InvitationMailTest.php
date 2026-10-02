<?php

use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sharing\Application\Jobs\SendInvitationMail;
use App\Modules\Sharing\Application\Mail\InvitationMail;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
 * The invitation mail (spec 016, FR-005, FR-025, FR-026; P-14; P-15 rule 4).
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    Mail::fake();
});

function runMailJob(string $invitationId, string $token): void
{
    app()->call([new SendInvitationMail($invitationId, $token), 'handle']);
}

/**
 * An email invitation row for the address, with a known token.
 *
 * @return array{id: string, token: string}
 */
function mailInvitation(string $inviterId, string $walletId, string $email): array
{
    return pendingInvitation($inviterId, $walletId, $email);
}

test('every mail string exists in both languages', function () {
    $en = require app_path('Modules/Sharing/Http/Lang/en/mail.php');
    $ar = require app_path('Modules/Sharing/Http/Lang/ar/mail.php');

    expect(array_keys(Arr::dot($ar)))->toBe(array_keys(Arr::dot($en)));
});

test('an unregistered address gets the create-an-account body in the inviter\'s language', function () {
    Profile::query()->where('user_id', $this->owner->id)->update(['locale' => 'ar']);
    $invitation = mailInvitation($this->owner->id, $this->wallet->id, 'newcomer@example.test');

    runMailJob($invitation['id'], $invitation['token']);

    Mail::assertSent(InvitationMail::class, 1);
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($invitation): bool {
        return $mail->hasTo('newcomer@example.test')
            && $mail->locale === 'ar'
            && $mail->registered === false
            && $mail->link === config('sharing.links.invitation_url').'?token='.$invitation['token'];
    });
});

test('a registered address gets the open-the-app body in its own language', function () {
    $invitee = person('invitee@example.test');
    Profile::query()->where('user_id', $invitee->id)->update(['locale' => 'ar']);
    Profile::query()->where('user_id', $this->owner->id)->update(['locale' => 'en']);
    $invitation = mailInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');

    runMailJob($invitation['id'], $invitation['token']);

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('invitee@example.test') && $mail->registered === true && $mail->locale === 'ar');
});

test('both bodies render in both languages with no wallet name, no amount and no missing key', function (bool $registered, string $locale) {
    $html = (new InvitationMail('http://saveme-backend.test/invite?token=abc', 'Mona', 'viewer', $registered))->locale($locale)->render();

    expect($html)->toContain('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"')
        ->toContain('Mona')
        ->toContain('http://saveme-backend.test/invite?token=abc')
        ->not->toContain('sharing::mail.')
        ->toUseWesternDigitsOnly();
})->with([[true, 'en'], [false, 'en'], [true, 'ar'], [false, 'ar']]);

test('the mail names the inviter when they set a name, and neither the wallet nor an amount', function () {
    $wallet = $this->wallet;
    $wallet->name = 'Secret savings plan';
    $wallet->save();
    Profile::query()->where('user_id', $this->owner->id)->update(['display_name_ciphertext' => app(AttributeCipher::class)->seal($this->owner->id, 'Mona')]);
    $invitation = mailInvitation($this->owner->id, $this->wallet->id, 'newcomer@example.test');

    runMailJob($invitation['id'], $invitation['token']);

    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail): bool {
        $html = $mail->render();

        return $mail->inviter === 'Mona' && str_contains($html, 'Mona') && ! str_contains($html, 'Secret savings plan') && ! str_contains($html, 'EGP');
    });
});

test('an inviter with no name gets the neutral phrase', function () {
    $invitation = mailInvitation($this->owner->id, $this->wallet->id, 'newcomer@example.test');

    runMailJob($invitation['id'], $invitation['token']);

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->inviter === null && str_contains($mail->render(), 'You have been invited'));
});

test('the sixth mail to one address in 24 hours is silently not sent', function () {
    $sent = 0;

    foreach (range(1, 7) as $n) {
        $invitation = mailInvitation($this->owner->id, $this->wallet->id, 'popular@example.test');
        runMailJob($invitation['id'], $invitation['token']);
        Invitation::query()->whereKey($invitation['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]);
    }

    Mail::assertSent(InvitationMail::class, 5);
    expect(RateLimiter::attempts('sharing-mail:'.app(InvitationAddresses::class)->normaliseAndLookup('popular@example.test')['lookup']))->toBe(5);
});

test('the budget is per address across inviters, not per inviter', function () {
    [, , $otherWallet] = plusOwner('second.owner@example.test');
    $other = User::query()->where('id', WalletMember::query()->where('wallet_id', $otherWallet->id)->value('user_id'))->firstOrFail();

    foreach (range(1, 3) as $n) {
        $one = mailInvitation($this->owner->id, $this->wallet->id, 'popular@example.test');
        runMailJob($one['id'], $one['token']);
        Invitation::query()->whereKey($one['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]);
        $two = mailInvitation($other->id, $otherWallet->id, 'popular@example.test');
        runMailJob($two['id'], $two['token']);
        Invitation::query()->whereKey($two['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]);
    }

    Mail::assertSent(InvitationMail::class, 5);
});

test('a closed invitation sends nothing', function () {
    $invitation = mailInvitation($this->owner->id, $this->wallet->id, 'newcomer@example.test');
    Invitation::query()->whereKey($invitation['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]);

    runMailJob($invitation['id'], $invitation['token']);
    runMailJob((string) Str::uuid7(), InvitationSecret::generate()->value);

    Mail::assertNothingSent();
});

test('a link invitation sends nothing', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);

    runMailJob($invitation['id'], $invitation['token']);

    Mail::assertNothingSent();
});

test('the queued job is encrypted at rest and a failure logs a warning with no identifier, the id at debug level', function () {
    $job = new SendInvitationMail('0192f7b0-0000-7000-8000-0000000000a1', 'a-token-that-must-not-be-logged');
    Log::spy();

    $job->failed(new RuntimeException('smtp said karim@example.test'));

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'sharing_invitation_mail_failed' && $context === [])->once();
    Log::shouldHaveReceived('debug')->withArgs(fn (string $message, array $context): bool => $message === 'sharing_invitation_mail_failed_ids'
        && $context === ['invitation_id' => '0192f7b0-0000-7000-8000-0000000000a1'])->once();
});
