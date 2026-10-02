<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sharing\Domain\Events\InvitationAccepted;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * Previewing, accepting and declining an invitation (spec 016, US1, US2, US4;
 * FR-010 to FR-014, FR-024; research R-7, R-8).
 */

beforeEach(function () {
    [$this->owner, $this->ownerCredential, $this->wallet] = plusOwner();
    [$this->invitee, $this->inviteeCredential] = walletCaller('invitee@example.test');
});

function inviteeMembership(string $walletId, string $userId): ?WalletMember
{
    return WalletMember::query()->where('wallet_id', $walletId)->where('user_id', $userId)->whereNull('deleted_at')->first();
}

test('an invitation addressed to the caller is accepted: membership, closure, audit, activity, event, in one write', function () {
    Event::fake([InvitationAccepted::class]);
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test', 'viewer');
    $walletBefore = Wallet::query()->findOrFail($this->wallet->id)->change_seq;
    $userBefore = (int) User::query()->whereKey($this->invitee->id)->value('change_seq');

    $response = respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertOk();

    $member = inviteeMembership($this->wallet->id, $this->invitee->id);
    $row = Invitation::query()->findOrFail($invitation['id']);
    expect($response->json('data.role'))->toBe('viewer')
        ->and($response->json('data.member_user_id'))->toBe($this->invitee->id)
        ->and($member->version)->toBe(1)
        ->and($member->member_change_seq)->toBe($userBefore + 1)
        ->and($member->change_seq)->toBeGreaterThan($walletBefore)
        ->and($row->status)->toBe('accepted')
        ->and($row->deleted_at)->not->toBeNull()
        ->and($row->responder_user_id)->toBe($this->invitee->id)
        ->and($row->address_sealed)->toBeNull()
        ->and($row->address_lookup)->toBeNull()
        ->and($row->change_seq)->toBeGreaterThan($member->change_seq)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBeGreaterThan($row->change_seq)
        ->and(AuditEntry::query()->where('event', 'membership_granted')->count())->toBe(1)
        ->and(WalletActivity::query()->where('action', 'joined')->where('actor_user_id', $this->invitee->id)->count())->toBe(1);
    Event::assertDispatched(InvitationAccepted::class, fn (InvitationAccepted $event): bool => $event->walletId === $this->wallet->id && $event->memberUserId === $this->invitee->id);

    // The token is dead afterwards, for another person.
    [, $other] = walletCaller('other@example.test');
    respondToInvitation($other, 'accept', $invitation['token'])->assertNotFound();
});

test('a mailed invitation is accepted by its addressee through the whole flow', function () {
    Mail::fake();
    sendInvitation($this->ownerCredential, $this->wallet->id, ['invitee_email' => 'Invitee@Example.test'])->assertCreated();

    respondToInvitation($this->inviteeCredential, 'accept', mailedToken())->assertOk();

    expect(inviteeMembership($this->wallet->id, $this->invitee->id))->not->toBeNull();
});

test('an email invitation addressed to someone else is 404 and changes nothing', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'someone.else@example.test');
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;

    $mismatch = respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertNotFound();
    $dead = respondToInvitation($this->inviteeCredential, 'accept', InvitationSecret::generate()->value)->assertNotFound();

    expect($mismatch->json('code'))->toBe('NOT_FOUND')
        ->and($mismatch->json('detail'))->toBe($dead->json('detail'))
        ->and(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('pending')
        ->and(inviteeMembership($this->wallet->id, $this->invitee->id))->toBeNull()
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBe($seq);
});

test('an unverified account may preview, accept and decline, but its wallet writes stay refused', function () {
    [$unverified, $credential] = walletCaller('unverified@example.test', verified: false);
    $first = pendingInvitation($this->owner->id, $this->wallet->id, 'unverified@example.test');

    respondToInvitation($credential, 'preview', $first['token'])->assertOk();
    respondToInvitation($credential, 'accept', $first['token'])->assertOk();
    expect(inviteeMembership($this->wallet->id, $unverified->id))->not->toBeNull();

    $second = pendingInvitation($this->owner->id, $this->wallet->id, 'unverified@example.test');
    respondToInvitation($credential, 'decline', $second['token'])->assertNoContent();

    $write = walletCall('POST', $credential, '/wallets/'.$this->wallet->id.'/archive', ['version' => 1])->assertForbidden();
    expect($write->json('code'))->toBe('EMAIL_NOT_VERIFIED');
});

test('an active member accepting gets their membership back, the invitation stays pending', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null, 'viewer');
    $mine = addMember($this->wallet->id, $this->invitee->id, 'editor');

    $response = respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertOk();

    expect($response->json('data.id'))->toBe($mine->id)
        ->and($response->json('data.role'))->toBe('editor')
        ->and(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('pending');
});

test('the same person retrying after a lost answer gets the same membership; another person gets 404', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    $first = respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertOk();
    $retry = respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertOk();

    expect($retry->json('data.id'))->toBe($first->json('data.id'))
        ->and(WalletMember::query()->where('user_id', $this->invitee->id)->count())->toBe(1);

    [, $other] = walletCaller('other@example.test');
    respondToInvitation($other, 'accept', $invitation['token'])->assertNotFound();
});

test('a person who left cannot reuse their own accepted token', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertOk();
    WalletMember::query()->where('user_id', $this->invitee->id)->update(['deleted_at' => now()]);

    respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertNotFound();
});

test('an invitation past its expiry instant is 404 before the sweep has run', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    Invitation::query()->whereKey($invitation['id'])->update(['expires_at' => now()->subSecond()]);

    respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertNotFound();
    respondToInvitation($this->inviteeCredential, 'preview', $invitation['token'])->assertNotFound();
    respondToInvitation($this->inviteeCredential, 'decline', $invitation['token'])->assertNotFound();
    expect(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('pending');
});

test('a deleted wallet makes its tokens dead', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    Wallet::query()->whereKey($this->wallet->id)->update(['deleted_at' => now()]);

    respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertNotFound();
    respondToInvitation($this->inviteeCredential, 'preview', $invitation['token'])->assertNotFound();
});

test('an owner without sharing on their plan cannot be joined, and the refusal names the capability', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    Subscription::query()->where('user_id', $this->owner->id)->delete();

    $response = respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertForbidden();

    expect($response->json('code'))->toBe('FEATURE_NOT_ENTITLED')
        ->and($response->json('feature'))->toBe('sharing.enabled')
        ->and(inviteeMembership($this->wallet->id, $this->invitee->id))->toBeNull()
        ->and(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('pending');
});

test('a full wallet refuses an acceptance, counting the wallet\'s other pending invitations', function () {
    foreach (range(1, 3) as $n) {
        addMember($this->wallet->id, person("m{$n}@example.test")->id, 'viewer');
    }

    $mine = pendingInvitation($this->owner->id, $this->wallet->id, null);
    $other = pendingInvitation($this->owner->id, $this->wallet->id, 'x@example.test');

    // Owner + 3 members + the other pending invitation + this one = 6 > 5.
    $response = respondToInvitation($this->inviteeCredential, 'accept', $mine['token'])->assertForbidden();
    expect($response->json('feature'))->toBe('sharing.max_members_per_wallet');

    Invitation::query()->whereKey($other['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]);
    respondToInvitation($this->inviteeCredential, 'accept', $mine['token'])->assertOk();
});

test('two acceptances racing for the last seat leave one member and one refusal', function () {
    foreach (range(1, 3) as $n) {
        addMember($this->wallet->id, person("m{$n}@example.test")->id, 'viewer');
    }

    [$second, $secondCredential] = walletCaller('second@example.test');
    $first = pendingInvitation($this->owner->id, $this->wallet->id, null);
    $other = pendingInvitation($this->owner->id, $this->wallet->id, null);
    Invitation::query()->whereKey($other['id'])->update(['expires_at' => now()->addDays(7)]);

    // Owner + 3 members: one seat left, two live invitations. Each acceptance counts the other.
    $one = respondToInvitation($this->inviteeCredential, 'accept', $first['token']);
    $two = respondToInvitation($secondCredential, 'accept', $other['token']);

    $statuses = [$one->getStatusCode(), $two->getStatusCode()];
    expect($statuses)->toEqualCanonicalizing([403, 403])
        ->and(WalletMember::query()->where('wallet_id', $this->wallet->id)->whereNull('deleted_at')->count())->toBe(4);

    // With one of the two withdrawn, exactly one seat is left and exactly one acceptance wins.
    Invitation::query()->whereKey($other['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]);
    respondToInvitation($this->inviteeCredential, 'accept', $first['token'])->assertOk();
    expect(WalletMember::query()->where('wallet_id', $this->wallet->id)->whereNull('deleted_at')->count())->toBe(5);
});

test('the last seat goes to one of two real concurrent acceptances', function () {
    foreach (range(1, 3) as $n) {
        addMember($this->wallet->id, person("m{$n}@example.test")->id, 'viewer');
    }
    [$second] = walletCaller('second@example.test');

    // One link, two people, one seat: the wallet lock serialises, the second sees `accepted`.
    $link = pendingInvitation($this->owner->id, $this->wallet->id, null);
    respondToInvitation($this->inviteeCredential, 'accept', $link['token'])->assertOk();
    $secondResponse = respondToInvitation(signInAs('second@example.test')->json('data.credential'), 'accept', $link['token']);

    expect($secondResponse->getStatusCode())->toBe(404)
        ->and(inviteeMembership($this->wallet->id, $second->id))->toBeNull();
});

test('declining closes the invitation as declined, erases the address and sets the responder; declining again is a no-op', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');

    respondToInvitation($this->inviteeCredential, 'decline', $invitation['token'])->assertNoContent();
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;
    respondToInvitation($this->inviteeCredential, 'decline', $invitation['token'])->assertNoContent();

    $row = Invitation::query()->findOrFail($invitation['id']);
    expect($row->status)->toBe('declined')
        ->and($row->address_sealed)->toBeNull()
        ->and($row->address_lookup)->toBeNull()
        ->and($row->responder_user_id)->toBe($this->invitee->id)
        ->and($row->version)->toBe(2)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBe($seq);

    [, $other] = walletCaller('other@example.test');
    respondToInvitation($other, 'decline', $invitation['token'])->assertNotFound();
    respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertNotFound();
});

test('declining is refused as 404 for an email invitation addressed to someone else', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'someone.else@example.test');

    respondToInvitation($this->inviteeCredential, 'decline', $invitation['token'])->assertNotFound();

    expect(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('pending');
});

test('the preview shows what is offered and nothing about money or the address', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test', 'viewer');

    $response = respondToInvitation($this->inviteeCredential, 'preview', $invitation['token'])->assertOk();

    expect($response->json('data'))->toMatchArray([
        'invitation_id' => $invitation['id'],
        'wallet_id' => $this->wallet->id,
        'wallet_name' => $this->wallet->name,
        'role' => 'viewer',
        'already_member' => false,
    ])->and(array_keys($response->json('data')))->toBe(['invitation_id', 'wallet_id', 'wallet_name', 'role', 'invited_by_display_name', 'expires_at', 'already_member'])
        ->and($response->getContent())->not->toContain('invitee@example.test')->not->toContain('balance');

    addMember($this->wallet->id, $this->invitee->id, 'viewer');
    expect(respondToInvitation($this->inviteeCredential, 'preview', $invitation['token'])->json('data.already_member'))->toBeTrue();
});

test('a link invitation is accepted by anyone signed in', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null, 'editor');

    respondToInvitation($this->inviteeCredential, 'accept', $invitation['token'])->assertOk();

    expect(inviteeMembership($this->wallet->id, $this->invitee->id)->role)->toBe('editor');
});

test('the token operations share a budget of 30 per hour per person', function () {
    config(['sharing.throttle.token_per_hour' => 3]);
    $token = InvitationSecret::generate()->value;

    foreach (range(1, 3) as $n) {
        respondToInvitation($this->inviteeCredential, 'preview', $token)->assertNotFound();
    }

    $limited = respondToInvitation($this->inviteeCredential, 'accept', $token)->assertStatus(429);
    expect($limited->json('code'))->toBe('RATE_LIMITED')->and($limited->headers->get('Retry-After'))->not->toBeNull();

    // Another person has their own budget, and a malformed body is 422 before 429.
    [, $other] = walletCaller('other@example.test');
    respondToInvitation($other, 'preview', $token)->assertNotFound();
    respondToInvitation($this->inviteeCredential, 'preview', 'not a token')->assertUnprocessable();
});
