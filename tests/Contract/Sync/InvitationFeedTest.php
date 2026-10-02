<?php

use App\Modules\Sharing\Application\ExpireInvitations;
use App\Modules\Sharing\Application\Jobs\SendInvitationMail;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use Illuminate\Support\Facades\Queue;

/*
 * Invitations on the wallet feed (spec 016, FR-022; spec 013 R-8): pending
 * live, closed as tombstones whose status says why, each row exactly its read,
 * in change_seq order with the wallet's other rows, readable by every member.
 */

beforeEach(function () {
    Queue::fake();
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
});

test('invitation rows equal their reads and sit in change_seq order with the wallet\'s other rows', function () {
    $email = (string) Str::uuid7();
    $link = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, [], $email)->assertCreated();
    sendInvitation($this->credential, $this->wallet->id, ['invitee_email' => null], $link)->assertCreated();

    $page = readFeed($this->credential, $this->wallet->id, null, 200)->assertValidRequest()->assertValidResponse(200);
    $rows = collect($page->json('data'));
    $invitations = $rows->where('type', 'invitation');

    expect($invitations)->toHaveCount(2)
        ->and($rows->pluck('type')->unique()->sort()->values()->all())->toBe(['invitation', 'wallet', 'wallet_member']);

    foreach ($invitations as $row) {
        $read = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$row['record']['id'])->assertOk()->json('data');
        expect($row['record'])->toBe($read);
    }

    $positions = $rows->map(fn (array $row): int => $row['record']['change_seq'])->all();
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted)
        ->and($invitations->firstWhere('record.id', $email)['record']['invitee_email'])->toBe('invitee@example.test');
});

test('a closed invitation is a tombstone whose status says why, with the address erased', function (string $how) {
    $id = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, [], $id)->assertCreated();
    $cursor = readFeedFully($this->credential, $this->wallet->id)['cursor'];

    match ($how) {
        'revoked' => walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.$id, ['version' => 1])->assertOk(),
        'expired' => (function () use ($id): void {
            Invitation::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]);
            app(ExpireInvitations::class)->run();
        })(),
        'declined' => respondToInvitation(walletCaller('invitee@example.test')[1], 'decline', tokenFromJob()),
        'accepted' => respondToInvitation(walletCaller('invitee@example.test')[1], 'accept', tokenFromJob()),
    };

    $rows = collect(readFeedFully($this->credential, $this->wallet->id, $cursor)['rows'])->where('type', 'invitation');
    $record = $rows->first()['record'];

    expect($rows)->toHaveCount(1)
        ->and($record)->toMatchArray(['id' => $id, 'status' => $how, 'invitee_email' => null, 'version' => 2])
        ->and($record['deleted_at'])->not->toBeNull();
})->with(['revoked', 'expired', 'declined', 'accepted']);

/**
 * The token of the invitation job the faked queue captured.
 */
function tokenFromJob(): string
{
    $token = null;
    Queue::assertPushed(SendInvitationMail::class, function ($job) use (&$token): bool {
        $token = $job->token;

        return true;
    });

    return (string) $token;
}

test('every member reads the wallet\'s invitations on the feed, a non-member gets 404', function () {
    sendInvitation($this->credential, $this->wallet->id)->assertCreated();
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    $viewerCredential = signInAs('viewer@example.test')->json('data.credential');

    $rows = collect(readFeedFully($viewerCredential, $this->wallet->id)['rows'])->where('type', 'invitation');
    expect($rows)->toHaveCount(1)->and($rows->first()['record']['invitee_email'])->toBe('invitee@example.test');

    [, $stranger] = walletCaller('stranger@example.test');
    readFeed($stranger, $this->wallet->id)->assertNotFound();
});

test('an invitation never rides a user feed', function () {
    sendInvitation($this->credential, $this->wallet->id)->assertCreated();

    expect(collect(readFeedFully($this->credential, 'user')['rows'])->pluck('type')->all())->not->toContain('invitation');
});

test('a wallet deleted with a pending invitation shows the tombstone to its former members', function () {
    $id = (string) Str::uuid7();
    sendInvitation($this->credential, $this->wallet->id, [], $id)->assertCreated();
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();

    $rows = collect(readFeedFully($this->credential, $this->wallet->id)['rows'])->where('type', 'invitation');

    expect($rows->first()['record'])->toMatchArray(['id' => $id, 'status' => 'revoked', 'invitee_email' => null])
        ->and($rows->first()['record']['deleted_at'])->not->toBeNull();
});
