<?php

use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;

/*
 * POST /invitations/decline (spec 016, US4; FR-013): every documented status.
 */

beforeEach(function () {
    [$this->owner, , $this->wallet] = plusOwner();
    [$this->invitee, $this->credential] = walletCaller('invitee@example.test');
    $this->invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
});

test('204 declines: no body, the invitation closed as declined with the address erased', function () {
    $response = respondToInvitation($this->credential, 'decline', $this->invitation['token'])->assertValidRequest()->assertValidResponse(204);

    $row = Invitation::query()->findOrFail($this->invitation['id']);
    expect($response->getContent())->toBe('')
        ->and($response->headers->get('RateLimit-Limit'))->toBe('30')
        ->and($row->status)->toBe('declined')
        ->and($row->responder_user_id)->toBe($this->invitee->id)
        ->and($row->address_sealed)->toBeNull();
});

test('204 again when the same person declines again', function () {
    respondToInvitation($this->credential, 'decline', $this->invitation['token'])->assertNoContent();

    respondToInvitation($this->credential, 'decline', $this->invitation['token'])->assertValidResponse(204);

    expect(Invitation::query()->findOrFail($this->invitation['id'])->version)->toBe(2);
});

test('404 for a dead token, an invitation addressed to someone else and another person after the decline', function () {
    $other = pendingInvitation($this->owner->id, $this->wallet->id, 'someone.else@example.test');

    respondToInvitation($this->credential, 'decline', InvitationSecret::generate()->value)->assertValidResponse(404);
    respondToInvitation($this->credential, 'decline', $other['token'])->assertValidResponse(404);

    respondToInvitation($this->credential, 'decline', $this->invitation['token'])->assertNoContent();
    [, $stranger] = walletCaller('stranger@example.test');
    respondToInvitation($stranger, 'decline', $this->invitation['token'])->assertValidResponse(404);

    expect(Invitation::query()->findOrFail($other['id'])->status)->toBe('pending');
});

test('422 for a missing or malformed token', function () {
    walletCall('POST', $this->credential, '/invitations/decline', [])->assertValidResponse(422);
    walletCall('POST', $this->credential, '/invitations/decline', ['token' => 'x'])->assertValidResponse(422);
});

test('429 beyond the hourly budget', function () {
    config(['sharing.throttle.token_per_hour' => 1]);

    respondToInvitation($this->credential, 'decline', InvitationSecret::generate()->value)->assertNotFound();
    respondToInvitation($this->credential, 'decline', InvitationSecret::generate()->value)->assertValidResponse(429);
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('POST', '/invitations/decline', ['token' => InvitationSecret::generate()->value], $this->credential);
});
