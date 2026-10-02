<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Sharing\Application\Jobs\SendInvitationMail;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
 * PUT /wallets/{id}/invitations/{iid} (spec 016, US1, US2; FR-001 to FR-009):
 * every documented status.
 */

beforeEach(function () {
    Queue::fake();
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    $this->id = (string) Str::uuid7();
});

test('creating an email invitation is 201 with the pending record and an empty meta', function () {
    $response = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'Karim@Example.test', 'version' => 0], $this->id)
        ->assertValidRequest()
        ->assertValidResponse(201);

    $response->assertHeader('X-Request-Id');
    expect($response->json('data'))->toMatchArray([
        'id' => $this->id,
        'wallet_id' => $this->wallet->id,
        'version' => 1,
        'deleted_at' => null,
        'role' => 'editor',
        'channel' => 'email',
        'invitee_email' => 'karim@example.test',
        'invited_by' => $this->owner->id,
        'status' => 'pending',
    ])->and(array_keys($response->json('data')))->toBe(['id', 'wallet_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'role', 'channel', 'invitee_email', 'invited_by', 'status', 'expires_at'])
        ->and($response->json('meta'))->toBe([])
        ->and($response->headers->get('RateLimit-Limit'))->toBe('20')
        ->and($response->headers->get('RateLimit-Remaining'))->toBe('19');
});

test('creating a link invitation is 201 with the one-time link in meta', function () {
    $response = sendInvitation($this->credential, $this->wallet->id, ['role' => 'viewer', 'invitee_email' => null], $this->id)
        ->assertValidRequest()
        ->assertValidResponse(201);

    expect($response->json('data.channel'))->toBe('link')
        ->and($response->json('data.invitee_email'))->toBeNull()
        ->and($response->json('meta.share_url'))->toStartWith(config('sharing.links.invitation_url').'?token=')
        ->and(array_keys($response->json('meta')))->toBe(['share_url']);

    $replay = sendInvitation($this->credential, $this->wallet->id, ['role' => 'viewer', 'invitee_email' => null], $this->id)->assertValidResponse(200);
    expect($replay->json('meta'))->toBe([]);
});

test('an identical replay is 200 with the same record, with version omitted, 0 or 1', function () {
    $created = sendInvitation($this->credential, $this->wallet->id, [], $this->id)->assertCreated()->json('data');

    foreach ([[], ['version' => 0], ['version' => 1]] as $extra) {
        sendInvitation($this->credential, $this->wallet->id, $extra, $this->id)
            ->assertValidRequest()
            ->assertValidResponse(200)
            ->assertJsonPath('data.change_seq', $created['change_seq'])
            ->assertJsonPath('data.version', 1);
    }

    expect(Invitation::query()->count())->toBe(1);
});

test('the answer is identical for a registered and an unregistered address', function () {
    person('registered@example.test');

    $registered = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'registered@example.test'])->assertValidResponse(201);
    $unregistered = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'unregistered@example.test'])->assertValidResponse(201);

    $strip = fn (array $data): array => array_diff_key($data, array_flip(['id', 'change_seq', 'created_at', 'updated_at', 'expires_at', 'invitee_email']));
    expect($strip($registered->json('data')))->toBe($strip($unregistered->json('data')))
        ->and($registered->getStatusCode())->toBe($unregistered->getStatusCode())
        ->and($registered->json('meta'))->toBe($unregistered->json('meta'));
});

test('an unverified sender is 403 EMAIL_NOT_VERIFIED, before anything else', function () {
    [$user, $credential] = walletCaller('unverified@example.test', verified: false);
    $wallet = ownedWallet($user->id);

    sendInvitation($credential, $wallet->id)->assertValidResponse(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    sendInvitation($credential, 'not-an-id')->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('an editor or a viewer is 403 FORBIDDEN; a non-member is 404', function (string $role) {
    $member = person("{$role}@example.test");
    addMember($this->wallet->id, $member->id, $role);
    $credential = signInAs("{$role}@example.test")->json('data.credential');

    sendInvitation($credential, $this->wallet->id)->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');

    [, $stranger] = walletCaller('stranger@example.test');
    sendInvitation($stranger, $this->wallet->id)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    sendInvitation($stranger, (string) Str::uuid7())->assertValidResponse(404);
    expect(Invitation::query()->count())->toBe(0);
})->with(['editor', 'viewer']);

test('a plan without sharing is 403 FEATURE_NOT_ENTITLED naming sharing.enabled; a full wallet names the quota', function () {
    [$free, $freeCredential] = walletCaller('free@example.test');
    $freeWallet = ownedWallet($free->id);

    $none = sendInvitation($freeCredential, $freeWallet->id)->assertValidResponse(403);
    expect($none->json('code'))->toBe('FEATURE_NOT_ENTITLED')->and($none->json('feature'))->toBe('sharing.enabled');

    foreach (range(1, 4) as $n) {
        sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => "p{$n}@example.test"])->assertCreated();
    }

    $full = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'late@example.test'])->assertValidResponse(403);
    expect($full->json('feature'))->toBe('sharing.max_members_per_wallet');
});

test('an owner whose plan lapsed can still replay an earlier create', function () {
    sendInvitation($this->credential, $this->wallet->id, [], $this->id)->assertCreated();
    Subscription::query()->where('user_id', $this->owner->id)->delete();
    app()->forgetScopedInstances();

    sendInvitation($this->credential, $this->wallet->id, [], $this->id)->assertValidResponse(200);
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'another@example.test'])->assertValidResponse(403);
});

test('a foreign invitation id, a wallet id and a transaction id are 404 and untouched', function () {
    [$other, $otherCredential] = walletCaller('grace@example.test');
    $otherWallet = ownedWallet($other->id);
    $theirs = pendingInvitation($other->id, $otherWallet->id);
    $transaction = Transaction::factory()->create(['wallet_id' => $this->wallet->id]);

    foreach ([$theirs['id'], $otherWallet->id, $transaction->id] as $held) {
        sendInvitation($this->credential, $this->wallet->id, [], $held)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
        sendInvitation($this->credential, $this->wallet->id, ['version' => 1], $held)->assertValidResponse(404);
    }

    expect(Invitation::query()->count())->toBe(1);
});

test('a version above 0 for an id that matches no record is 404 and creates nothing', function () {
    sendInvitation($this->credential, $this->wallet->id, ['version' => 3], $this->id)->assertValidResponse(404);

    expect(Invitation::query()->count())->toBe(0);
});

test('a closed invitation is 409 with the tombstone in current, even for an identical body', function () {
    $invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    Invitation::query()->whereKey($invitation['id'])->update(['status' => 'revoked', 'deleted_at' => now(), 'version' => 2, 'address_sealed' => null, 'address_lookup' => null]);

    $response = sendInvitation($this->credential, $this->wallet->id, ['version' => 2], $invitation['id'])->assertValidResponse(409);

    expect($response->json('code'))->toBe('VERSION_CONFLICT')
        ->and($response->json('current.status'))->toBe('revoked')
        ->and($response->json('current.deleted_at'))->not->toBeNull()
        ->and($response->json('current.invitee_email'))->toBeNull();
});

test('a stale version on a changed body is 409; an unchanged version on a changed member is 422 IMMUTABLE_FIELD', function () {
    sendInvitation($this->credential, $this->wallet->id, [], $this->id)->assertCreated();

    $stale = sendInvitation($this->credential, $this->wallet->id, ['role' => 'viewer', 'version' => 5], $this->id)->assertValidResponse(409);
    expect($stale->json('current.role'))->toBe('editor');

    $immutable = sendInvitation($this->credential, $this->wallet->id, ['role' => 'viewer', 'invitee_email' => 'other@example.test', 'version' => 1], $this->id)->assertValidResponse(422);
    expect($immutable->json('code'))->toBe('VALIDATION_FAILED')
        ->and(array_column($immutable->json('errors'), 'pointer'))->toBe(['/role', '/invitee_email'])
        ->and(array_unique(array_column($immutable->json('errors'), 'code')))->toBe(['IMMUTABLE_FIELD']);
});

test('invalid bodies are 422 with their field codes, an unknown member included', function (array $body, string $pointer, string $code) {
    $response = walletCall('PUT', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$this->id, $body);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    if ($code !== 'UNKNOWN_FIELD') {
        $response->assertValidResponse(422);
    }
    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code)
        ->and(Invitation::query()->count())->toBe(0);
})->with([
    'owner role' => [['role' => 'owner', 'invitee_email' => 'a@example.test'], '/role', 'INVALID_ENUM_VALUE'],
    'missing role' => [['invitee_email' => 'a@example.test'], '/role', 'REQUIRED'],
    'missing address member' => [['role' => 'editor'], '/invitee_email', 'REQUIRED'],
    'not an address' => [['role' => 'editor', 'invitee_email' => 'not an address'], '/invitee_email', 'INVALID_TYPE'],
    'too long' => [['role' => 'editor', 'invitee_email' => str_repeat('a', 250).'@example.test'], '/invitee_email', 'OUT_OF_RANGE'],
    'a number' => [['role' => 'editor', 'invitee_email' => 5], '/invitee_email', 'INVALID_TYPE'],
    'negative version' => [['role' => 'editor', 'invitee_email' => null, 'version' => -1], '/version', 'OUT_OF_RANGE'],
    'unknown member' => [['role' => 'editor', 'invitee_email' => null, 'status' => 'accepted'], '/status', 'UNKNOWN_FIELD'],
]);

test('a malformed identifier is 422 INVALID_ID, before the budget and the body', function () {
    config(['sharing.throttle.invites_per_day' => 0]);

    walletCall('PUT', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/not-an-id', ['role' => 'editor', 'invitee_email' => null])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    walletCall('PUT', $this->credential, '/wallets/'.(string) Str::uuid().'/invitations/'.$this->id, [])->assertJsonPath('code', 'INVALID_ID');
});

test('the 21st create in 24 hours is 429 with Retry-After and the rate-limit headers, before the body is read', function () {
    RateLimiter::hit('sharing-invite:'.$this->owner->id, 86400);
    config(['sharing.throttle.invites_per_day' => 1]);

    $response = sendInvitation($this->credential, $this->wallet->id)->assertValidResponse(429);
    expect($response->json('code'))->toBe('RATE_LIMITED')
        ->and($response->json('retry_after_seconds'))->toBeInt()
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('RateLimit-Remaining'))->toBe('0')
        ->and($response->headers->get('RateLimit-Reset'))->not->toBeNull();

    walletCall('PUT', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$this->id, [])->assertStatus(429);
    expect(Invitation::query()->count())->toBe(0);
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('PUT', '/wallets/'.$this->wallet->id.'/invitations/'.$this->id, ['role' => 'editor', 'invitee_email' => null], $this->credential);
});

test('mail is queued for an email invitation only, and a mailed link opens the same invitation', function () {
    Queue::fake();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => null])->assertCreated();
    Queue::assertNothingPushed();

    sendInvitation($this->credential, $this->wallet->id)->assertCreated();
    Queue::assertPushed(SendInvitationMail::class, 1);
});
