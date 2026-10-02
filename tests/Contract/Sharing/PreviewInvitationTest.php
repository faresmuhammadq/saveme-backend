<?php

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\RateLimiter;

/*
 * POST /invitations/preview (spec 016, US1; FR-010, FR-011): every documented
 * status. No amount, no balance, no address; every token problem is 404.
 */

beforeEach(function () {
    [$this->owner, , $this->wallet] = plusOwner();
    [$this->invitee, $this->credential] = walletCaller('invitee@example.test');
});

test('200 shows what the invitation offers and changes nothing', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test', 'viewer');
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;

    $response = respondToInvitation($this->credential, 'preview', $invitation['token'])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray(['invitation_id' => $invitation['id'], 'wallet_id' => $this->wallet->id, 'wallet_name' => $this->wallet->name, 'role' => 'viewer', 'already_member' => false])
        ->and($response->json('meta'))->toBe([])
        ->and($response->headers->get('RateLimit-Limit'))->toBe('30')
        ->and($response->getContent())->not->toContain('invitee@example.test')
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBe($seq)
        ->and(Invitation::query()->findOrFail($invitation['id'])->status)->toBe('pending');
});

test('the inviter\'s display name is shown when they set one', function () {
    Profile::query()->where('user_id', $this->owner->id)->update([
        'display_name_ciphertext' => app(AttributeCipher::class)->seal($this->owner->id, 'Mona'),
    ]);
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, null);

    respondToInvitation($this->credential, 'preview', $invitation['token'])->assertValidResponse(200)->assertJsonPath('data.invited_by_display_name', 'Mona');
});

test('404 for an unknown, used, revoked, expired, mismatched or deleted-wallet token, with one answer for all', function (string $case) {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    $token = $invitation['token'];

    match ($case) {
        'unknown' => $token = InvitationSecret::generate()->value,
        'used' => Invitation::query()->whereKey($invitation['id'])->update(['status' => 'accepted', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]),
        'revoked' => Invitation::query()->whereKey($invitation['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'address_sealed' => null, 'address_lookup' => null]),
        'expired' => Invitation::query()->whereKey($invitation['id'])->update(['expires_at' => now()->subMinute()]),
        'mismatch' => $this->credential = walletCaller('other@example.test')[1],
        'deleted wallet' => Wallet::query()->whereKey($this->wallet->id)->update(['deleted_at' => now()]),
    };

    $response = respondToInvitation($this->credential, 'preview', $token)->assertValidResponse(404);

    expect($response->json('code'))->toBe('NOT_FOUND');
})->with(['unknown', 'used', 'revoked', 'expired', 'mismatch', 'deleted wallet']);

test('422 for a missing or malformed token, an unknown member included', function (array $body, string $code) {
    $response = walletCall('POST', $this->credential, '/invitations/preview', $body)->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/token')['code'] ?? null)->toBe($code);
})->with([
    'missing' => [[], 'REQUIRED'],
    'short' => [['token' => 'abc'], 'INVALID_TYPE'],
    'not base64url' => [['token' => str_repeat('+', 43)], 'INVALID_TYPE'],
    'a number' => [['token' => 5], 'INVALID_TYPE'],
]);

test('an unknown member is UNKNOWN_FIELD', function () {
    $response = walletCall('POST', $this->credential, '/invitations/preview', ['token' => InvitationSecret::generate()->value, 'wallet_id' => 'x'])->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/wallet_id', 'code' => 'UNKNOWN_FIELD']);
});

test('429 beyond 30 calls an hour, with Retry-After', function () {
    config(['sharing.throttle.token_per_hour' => 2]);
    $token = InvitationSecret::generate()->value;

    respondToInvitation($this->credential, 'preview', $token)->assertNotFound();
    respondToInvitation($this->credential, 'preview', $token)->assertNotFound();
    $response = respondToInvitation($this->credential, 'preview', $token)->assertValidResponse(429);

    expect($response->json('code'))->toBe('RATE_LIMITED')->and($response->headers->get('Retry-After'))->not->toBeNull();
    RateLimiter::clear('sharing-token:'.$this->invitee->id);
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('POST', '/invitations/preview', ['token' => InvitationSecret::generate()->value], $this->credential);
});
