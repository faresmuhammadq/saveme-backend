<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;

/*
 * POST /invitations/accept (spec 016, US1; FR-012 to FR-014): every documented
 * status, and what the invitee's feeds then carry.
 */

beforeEach(function () {
    [$this->owner, $this->ownerCredential, $this->wallet] = plusOwner();
    [$this->invitee, $this->credential] = walletCaller('invitee@example.test');
});

test('200 returns the new membership as the member list renders it', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test', 'editor');

    $response = respondToInvitation($this->credential, 'accept', $invitation['token'])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray(['wallet_id' => $this->wallet->id, 'version' => 1, 'member_user_id' => $this->invitee->id, 'role' => 'editor', 'deleted_at' => null])
        ->and($response->json('meta'))->toBe([])
        ->and($response->headers->get('RateLimit-Limit'))->toBe('30');

    // The same record the member list renders (Sharing keeps its own copy of the shape).
    $list = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members')->assertValidResponse(200);
    expect(collect($list->json('data'))->firstWhere('member_user_id', $this->invitee->id))->toBe($response->json('data'));
});

test('the invitee\'s user feed carries the live wallet_member and the wallet feed the invitation tombstone', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    respondToInvitation($this->credential, 'accept', $invitation['token'])->assertOk();

    $userRows = readFeedFully($this->credential, 'user')['rows'];
    $membership = collect($userRows)->first(fn (array $row): bool => $row['type'] === 'wallet_member' && $row['record']['wallet_id'] === $this->wallet->id);
    expect($membership['record'])->toMatchArray(['member_user_id' => $this->invitee->id, 'deleted_at' => null]);

    $walletRows = readFeedFully($this->credential, $this->wallet->id)['rows'];
    $closed = collect($walletRows)->first(fn (array $row): bool => $row['type'] === 'invitation');
    expect($closed['record'])->toMatchArray(['id' => $invitation['id'], 'status' => 'accepted', 'invitee_email' => null])
        ->and($closed['record']['deleted_at'])->not->toBeNull();
});

test('403 FEATURE_NOT_ENTITLED names the capability: no sharing, or a full wallet', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);
    foreach (range(1, 4) as $n) {
        addMember($this->wallet->id, person("m{$n}@example.test")->id, 'viewer');
    }

    $full = respondToInvitation($this->credential, 'accept', $invitation['token'])->assertValidResponse(403);
    expect($full->json('code'))->toBe('FEATURE_NOT_ENTITLED')->and($full->json('feature'))->toBe('sharing.max_members_per_wallet');

    Subscription::query()->where('user_id', $this->owner->id)->delete();
    app()->forgetScopedInstances();
    $none = respondToInvitation($this->credential, 'accept', $invitation['token'])->assertValidResponse(403);
    expect($none->json('feature'))->toBe('sharing.enabled');
});

test('404 for every dead or mismatched token, one answer for all', function () {
    $mismatch = pendingInvitation($this->owner->id, $this->wallet->id, 'someone.else@example.test');
    $used = pendingInvitation($this->owner->id, $this->wallet->id, null);
    respondToInvitation(walletCaller('first@example.test')[1], 'accept', $used['token'])->assertOk();

    $answers = [
        respondToInvitation($this->credential, 'accept', $mismatch['token'])->assertValidResponse(404),
        respondToInvitation($this->credential, 'accept', $used['token'])->assertValidResponse(404),
        respondToInvitation($this->credential, 'accept', InvitationSecret::generate()->value)->assertValidResponse(404),
    ];

    expect(collect($answers)->map(fn ($response): string => $response->json('detail').$response->json('code'))->unique())->toHaveCount(1)
        ->and(Invitation::query()->findOrFail($mismatch['id'])->status)->toBe('pending');
});

test('422 for a missing or malformed token', function () {
    walletCall('POST', $this->credential, '/invitations/accept', [])->assertValidResponse(422);
    walletCall('POST', $this->credential, '/invitations/accept', ['token' => 'short'])->assertValidResponse(422);
});

test('429 beyond the hourly budget, after a malformed body is already 422', function () {
    config(['sharing.throttle.token_per_hour' => 1]);
    $token = InvitationSecret::generate()->value;

    respondToInvitation($this->credential, 'accept', $token)->assertNotFound();
    respondToInvitation($this->credential, 'accept', $token)->assertValidResponse(429);
    walletCall('POST', $this->credential, '/invitations/accept', ['token' => 'short'])->assertValidResponse(422);
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('POST', '/invitations/accept', ['token' => InvitationSecret::generate()->value], $this->credential);
});
