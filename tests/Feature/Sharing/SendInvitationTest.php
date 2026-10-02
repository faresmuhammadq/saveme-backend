<?php

use App\Modules\Sharing\Application\Jobs\SendInvitationMail;
use App\Modules\Sharing\Domain\Events\InvitationSent;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Sending an invitation (spec 016, US1, US2; FR-001 to FR-009, FR-025, FR-026).
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    Queue::fake();
});

test('creating stores a pending invitation for 7 days with the address sealed and only the token hash', function () {
    $id = (string) Str::uuid7();
    $response = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => ' Karim@Example.TEST '], $id)->assertCreated();

    $row = Invitation::query()->findOrFail($id);
    expect($response->json('data.invitee_email'))->toBe('karim@example.test')
        ->and($row->status)->toBe('pending')
        ->and($row->channel)->toBe('email')
        ->and($row->version)->toBe(1)
        ->and($row->invited_by)->toBe($this->owner->id)
        ->and($row->expires_at->diffInDays($row->created_at, true))->toBeGreaterThan(6.99)->toBeLessThan(7.01)
        ->and($row->address_sealed)->not->toContain('karim')
        ->and($row->address_lookup)->toHaveLength(64)
        ->and($row->token_hash)->toHaveLength(64);

    Queue::assertPushed(SendInvitationMail::class, fn (SendInvitationMail $job): bool => $row->token_hash === InvitationSecret::hashOf($job->token));
    expect(DB::table('invitations')->get()->toJson())->not->toContain('karim@example.test');
});

test('the invitation takes a stamp from the wallet counter and the wallet takes the last', function () {
    $before = Wallet::query()->findOrFail($this->wallet->id)->change_seq;
    $id = (string) Str::uuid7();

    sendInvitation($this->credential, $this->wallet->id, [], $id)->assertCreated();

    $invitation = Invitation::query()->findOrFail($id);
    $wallet = Wallet::query()->findOrFail($this->wallet->id);
    expect($invitation->change_seq)->toBeGreaterThan($before)
        ->and($wallet->change_seq)->toBeGreaterThan($invitation->change_seq)
        ->and($wallet->version)->toBe($this->wallet->version);
});

test('an identical replay with any version changes nothing and sends nothing again', function () {
    $id = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, [], $id)->assertCreated();
    $seq = Wallet::query()->findOrFail($this->wallet->id)->change_seq;

    sendInvitation($this->credential, $this->wallet->id, ['version' => 0], $id)->assertOk();
    sendInvitation($this->credential, $this->wallet->id, ['version' => 7], $id)->assertOk();

    expect(Invitation::query()->count())->toBe(1)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->change_seq)->toBe($seq);
    Queue::assertPushed(SendInvitationMail::class, 1);
});

test('only an email invitation queues a mail; a link invitation returns the link once and stores no address', function () {
    $id = (string) Str::uuid7();
    $created = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => null, 'role' => 'viewer'], $id)->assertCreated();

    $link = $created->json('meta.share_url');
    $token = tokenOf($link);
    expect($link)->toStartWith(config('sharing.links.invitation_url').'?token=')
        ->and($token)->toHaveLength(43)
        ->and($created->json('data.channel'))->toBe('link')
        ->and($created->json('data.invitee_email'))->toBeNull()
        ->and(Invitation::query()->findOrFail($id)->address_sealed)->toBeNull()
        ->and(Invitation::query()->findOrFail($id)->token_hash)->toBe(InvitationSecret::hashOf($token));
    Queue::assertNotPushed(SendInvitationMail::class);

    $replay = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => null, 'role' => 'viewer'], $id)->assertOk();
    expect($replay->json('meta'))->toBe([]);
});

test('the request does the same work whether or not the address has an account', function () {
    person('registered@example.test');
    // A first create warms what is per-process (prepared plans, scoped memos), so the two measured ones are alike.
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'warmup@example.test'])->assertCreated();

    DB::enableQueryLog();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'registered@example.test'])->assertCreated();
    $registered = array_column(DB::getQueryLog(), 'query');
    DB::flushQueryLog();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'nobody@example.test'])->assertCreated();
    $unregistered = array_column(DB::getQueryLog(), 'query');

    // No account is looked up by address on the request path: only the queued job does that.
    foreach ([...$registered, ...$unregistered] as $sql) {
        expect($sql)->not->toContain('"email_lookup"');
    }
    expect(count($registered))->toBe(count($unregistered));
});

test('a new invitation to a pending address supersedes it in the same write', function () {
    $first = (string) Str::uuid7();
    $second = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, [], $first)->assertCreated();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'INVITEE@example.test', 'role' => 'viewer'], $second)->assertCreated();

    $old = Invitation::query()->findOrFail($first);
    $new = Invitation::query()->findOrFail($second);
    expect($old->status)->toBe('revoked')
        ->and($old->deleted_at)->not->toBeNull()
        ->and($old->version)->toBe(2)
        ->and($old->address_sealed)->toBeNull()
        ->and($old->address_lookup)->toBeNull()
        ->and($old->change_seq)->not->toBe($new->change_seq)
        ->and($new->status)->toBe('pending');
});

test('the quota counts pending invitations; Free is refused as no sharing', function () {
    foreach (range(1, 4) as $n) {
        sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => "p{$n}@example.test"])->assertCreated();
    }

    $full = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'late@example.test'])->assertForbidden();
    expect($full->json('code'))->toBe('FEATURE_NOT_ENTITLED')->and($full->json('feature'))->toBe('sharing.max_members_per_wallet');

    [$free, $freeCredential] = walletCaller('free@example.test');
    $freeWallet = ownedWallet($free->id);
    $none = sendInvitation($freeCredential, $freeWallet->id)->assertForbidden();
    expect($none->json('code'))->toBe('FEATURE_NOT_ENTITLED')->and($none->json('feature'))->toBe('sharing.enabled');
});

test('superseding does not use a seat of its own', function () {
    foreach (range(1, 4) as $n) {
        sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => "p{$n}@example.test"])->assertCreated();
    }

    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'p1@example.test'])->assertCreated();

    expect(Invitation::query()->whereNull('deleted_at')->count())->toBe(4);
});

test('the invite budget counts applied creates only', function () {
    config(['sharing.throttle.invites_per_day' => 3]);
    $id = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, [], $id)->assertCreated();

    // A replay and a refusal cost nothing.
    sendInvitation($this->credential, $this->wallet->id, [], $id)->assertOk();
    sendInvitation($this->credential, $this->wallet->id, ['role' => 'owner'])->assertUnprocessable();
    expect(RateLimiter::attempts('sharing-invite:'.$this->owner->id))->toBe(1);

    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'a@example.test'])->assertCreated();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'b@example.test'])->assertCreated();

    $limited = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'c@example.test'])->assertStatus(429);
    expect($limited->json('code'))->toBe('RATE_LIMITED')->and($limited->headers->get('Retry-After'))->not->toBeNull();
    // The budget is checked before the body: a malformed body is 429 as well.
    sendInvitation($this->credential, $this->wallet->id, ['role' => 'owner'])->assertStatus(429);
});

test('sending records one activity entry and announces the invitation after commit', function () {
    Event::fake([InvitationSent::class]);
    $id = (string) Str::uuid7();

    sendInvitation($this->credential, $this->wallet->id, ['role' => 'viewer'], $id)->assertCreated();
    sendInvitation($this->credential, $this->wallet->id, ['role' => 'viewer'], $id)->assertOk();

    $entries = WalletActivity::query()->where('wallet_id', $this->wallet->id)->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->action)->toBe('invited')
        ->and($entries[0]->subject_type)->toBe('invitation')
        ->and($entries[0]->subject_id)->toBe($id)
        ->and($entries[0]->actor_user_id)->toBe($this->owner->id)
        ->and($entries[0]->role)->toBe('viewer');
    Event::assertDispatchedTimes(InvitationSent::class, 1);
    Event::assertDispatched(InvitationSent::class, fn (InvitationSent $event): bool => $event->invitationId === $id && $event->channel === 'email');
});

test('the info log line carries no record identifier, address or token; the identifiers are debug-level', function () {
    Log::spy();
    $response = sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => 'secret.person@example.test'])->assertCreated();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => $message === 'sharing_invitation_created'
        && $context === ['channel' => 'email', 'role' => 'editor'])->once();
    Log::shouldHaveReceived('debug')->withArgs(fn (string $message, array $context = []): bool => $message === 'sharing_invitation_created_ids'
        && array_keys($context) === ['invitation_id', 'wallet_id'] && ! str_contains(json_encode($context), 'secret.person'))->once();
    expect($response->json('data.invitee_email'))->toBe('secret.person@example.test');
});
