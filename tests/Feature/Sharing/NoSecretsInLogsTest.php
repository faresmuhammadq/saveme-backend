<?php

use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Sharing\Application\ExpireInvitations;
use App\Modules\Sharing\Application\Jobs\SendInvitationMail;
use App\Modules\Sharing\Application\Mail\InvitationMail;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
 * The privacy sweep (spec 016, SC-009; P-15 rule 4; security baseline): across
 * create, the mail job, accept, decline, revoke and the sweep, no token, no
 * invited address and no lookup value appears in any log line, audit detail or
 * activity row.
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner('owner@example.test');
    $this->logged = [];
    Log::listen(function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
    Mail::fake();
});

/**
 * Everything the application wrote down: log lines, audit entries, activity rows.
 */
function writtenDown(object $test): string
{
    return implode("\n", [
        ...$test->logged,
        DB::table('audit_entries')->get()->toJson(),
        DB::table('wallet_activity')->get()->toJson(),
    ]);
}

test('no token, address or lookup value reaches a log line, an audit entry or an activity row', function () {
    $addresses = app(InvitationAddresses::class);
    $secrets = [];
    $emails = ['karim.invited@example.test', 'declined.person@example.test', 'revoked.person@example.test', 'expiring.person@example.test'];
    $lookups = array_map(fn (string $email): string => $addresses->normaliseAndLookup($email)['lookup'], $emails);

    $karim = person('karim.invited@example.test');
    $karimCredential = signInAs('karim.invited@example.test')->json('data.credential');
    $decliner = person('declined.person@example.test');
    $declinerCredential = signInAs('declined.person@example.test')->json('data.credential');

    $accepted = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => $emails[0]], $accepted)->assertCreated();
    $secrets[] = mailedToken();
    respondToInvitation($karimCredential, 'preview', end($secrets))->assertOk();
    respondToInvitation($karimCredential, 'accept', end($secrets))->assertOk();

    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => $emails[1]])->assertCreated();
    $secrets[] = mailedToken();
    respondToInvitation($declinerCredential, 'decline', end($secrets))->assertNoContent();

    $revoked = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => $emails[2]], $revoked)->assertCreated();
    $secrets[] = mailedToken();
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$revoked, ['version' => 1])->assertOk();

    $expiring = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => $emails[3]], $expiring)->assertCreated();
    $secrets[] = mailedToken();
    Invitation::query()->whereKey($expiring)->update(['expires_at' => now()->subMinute()]);
    app(ExpireInvitations::class)->run();

    $link = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => null])->assertCreated();
    $secrets[] = tokenOf($link->json('meta.share_url'));

    respondToInvitation($karimCredential, 'accept', 'x'.str_repeat('a', 42))->assertNotFound();
    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.$karim->id, ['role' => 'viewer', 'version' => 1])->assertOk();

    $text = writtenDown($this);
    expect(Mail::sent(InvitationMail::class))->toHaveCount(4);

    foreach ($secrets as $secret) {
        expect($text)->not->toContain($secret)->not->toContain(hash('sha256', $secret));
    }

    foreach ([...$emails, ...$lookups, 'karim.invited', 'declined.person'] as $value) {
        expect($text)->not->toContain($value);
    }

    expect($text)->toContain('sharing_invitation_created')->toContain('sharing_invitation_accepted');
});

test('a failing mail job logs no address and no token', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'leaky.person@example.test');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp refused leaky.person@example.test'));

    $job = new SendInvitationMail($invitation['id'], $invitation['token']);
    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException $exception) {
        $job->failed($exception);
    }

    $text = implode("\n", $this->logged);
    expect($text)->toContain($invitation['id'])->not->toContain('leaky.person')->not->toContain($invitation['token']);
});
