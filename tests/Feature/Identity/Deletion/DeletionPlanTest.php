<?php

use App\Modules\Identity\Application\Deletion\DeletionPlanner;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Who inherits what, before it happens (spec 022, FR-033, FR-035 to FR-038):
 * the preview, the choices and their validation, and the one rule shared with
 * the purge.
 */

beforeEach(function () {
    Mail::fake();
    [$this->user, $this->credential] = walletCaller();
    $this->pat = person('pat@example.test');
    $this->quinn = person('quinn@example.test');

    // Two members besides the owner: the owner's choice is expected.
    $this->shared = ownedWallet($this->user->id, ['name' => 'Shared']);
    addMember($this->shared->id, $this->pat->id, 'viewer');
    addMember($this->shared->id, $this->quinn->id, 'editor');

    // One other member, a viewer: they inherit directly.
    $this->pair = ownedWallet($this->user->id, ['name' => 'Pair']);
    addMember($this->pair->id, $this->pat->id, 'viewer');

    $this->solo = ownedWallet($this->user->id, ['name' => 'Solo']);
});

function planOf(string $credential): array
{
    return exportCall('GET', $credential, '/account/deletion-request')->assertOk()->json('data');
}

function requestDeletionWith(string $credential, ?array $successors = null, string $password = 'correct horse'): TestResponse
{
    return exportCall('POST', $credential, '/account/deletion-request', array_filter(['password' => $password, 'successors' => $successors], fn ($value) => $value !== null));
}

test('the plan of an active account lists each shared wallet it owns, solo wallets absent', function () {
    $plan = planOf($this->credential);
    $byWallet = collect($plan['successors'])->keyBy('wallet_id');

    expect($plan['state'])->toBe('active')
        ->and($plan['deletion_requested_at'])->toBeNull()->and($plan['deletion_scheduled_for'])->toBeNull()
        ->and($plan['successors'])->toHaveCount(2)
        ->and($byWallet[$this->pair->id])->toBe(['wallet_id' => $this->pair->id, 'successor_user_id' => $this->pat->id, 'reason' => 'only_member', 'choice_expected' => false])
        ->and($byWallet[$this->shared->id])->toBe(['wallet_id' => $this->shared->id, 'successor_user_id' => $this->quinn->id, 'reason' => 'oldest_editor', 'choice_expected' => true])
        ->and($byWallet->has($this->solo->id))->toBeFalse();
});

test('an archived shared wallet is in the plan, a deleted one is not', function () {
    $archived = ownedWallet($this->user->id, ['archived_at' => now()]);
    addMember($archived->id, $this->pat->id, 'editor');
    $deleted = ownedWallet($this->user->id, ['deleted_at' => now()]);
    addMember($deleted->id, $this->pat->id, 'editor');

    $ids = collect(planOf($this->credential)['successors'])->pluck('wallet_id');

    expect($ids)->toContain($archived->id)->not->toContain($deleted->id);
});

test('the plan of a pending account carries its dates', function () {
    requestDeletionWith($this->credential)->assertOk();

    $plan = planOf($this->credential);

    expect($plan['state'])->toBe('pending_deletion')
        ->and($plan['deletion_requested_at'])->not->toBeNull()
        ->and($plan['deletion_scheduled_for'])->not->toBeNull();
});

test('a request with valid successors stores them and answers with the choice', function () {
    $response = requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]])->assertOk();

    $answer = collect($response->json('data.successors'))->keyBy('wallet_id');
    expect($response->json('data.state'))->toBe('pending_deletion')
        ->and($answer[$this->shared->id]['reason'])->toBe('chosen')
        ->and($answer[$this->shared->id]['successor_user_id'])->toBe($this->pat->id)
        ->and($answer[$this->pair->id]['reason'])->toBe('only_member')
        ->and(DB::table('deletion_successor_choices')->where('user_id', $this->user->id)->count())->toBe(1);

    expect(collect(planOf($this->credential)['successors'])->firstWhere('wallet_id', $this->shared->id)['reason'])->toBe('chosen');
});

test('a request with only the password stays valid and stores no choice', function () {
    $response = requestDeletionWith($this->credential)->assertOk();

    expect($response->json('data.successors'))->toHaveCount(2)
        ->and(DB::table('deletion_successor_choices')->count())->toBe(0);
});

test('every kind of bad choice is refused at its pointer and changes nothing', function (Closure $entries, string $pointer, string $code) {
    $response = requestDeletionWith($this->credential, $entries($this));

    $response->assertStatus(422)->assertJsonPath('errors.0.pointer', $pointer)->assertJsonPath('errors.0.code', $code);
    expect(User::query()->find($this->user->id)->state)->toBe('active')
        ->and(DB::table('deletion_successor_choices')->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'deletion_requested')->count())->toBe(0);
})->with([
    'a wallet the person does not own' => [fn ($t) => [['wallet_id' => ownedWallet($t->pat->id)->id, 'member_user_id' => $t->quinn->id]], '/successors/0/wallet_id', 'INVALID_ENUM_VALUE'],
    'a solo wallet' => [fn ($t) => [['wallet_id' => $t->solo->id, 'member_user_id' => $t->pat->id]], '/successors/0/wallet_id', 'INVALID_ENUM_VALUE'],
    'an unknown wallet' => [fn ($t) => [['wallet_id' => (string) Str::uuid7(), 'member_user_id' => $t->pat->id]], '/successors/0/wallet_id', 'INVALID_ENUM_VALUE'],
    'oneself' => [fn ($t) => [['wallet_id' => $t->shared->id, 'member_user_id' => $t->user->id]], '/successors/0/member_user_id', 'INVALID_ENUM_VALUE'],
    'a non-member' => [fn ($t) => [['wallet_id' => $t->shared->id, 'member_user_id' => (string) Str::uuid7()]], '/successors/0/member_user_id', 'INVALID_ENUM_VALUE'],
    'a member of another wallet only' => [fn ($t) => [['wallet_id' => $t->pair->id, 'member_user_id' => $t->quinn->id]], '/successors/0/member_user_id', 'INVALID_ENUM_VALUE'],
    'a duplicated wallet' => [fn ($t) => [['wallet_id' => $t->shared->id, 'member_user_id' => $t->pat->id], ['wallet_id' => $t->shared->id, 'member_user_id' => $t->quinn->id]], '/successors/1/wallet_id', 'INVALID_ENUM_VALUE'],
    'a malformed wallet id' => [fn ($t) => [['wallet_id' => 'nope', 'member_user_id' => $t->pat->id]], '/successors/0/wallet_id', 'INVALID_ID'],
    'a malformed member id' => [fn ($t) => [['wallet_id' => $t->shared->id, 'member_user_id' => 'nope']], '/successors/0/member_user_id', 'INVALID_ID'],
    'more than 100 entries' => [fn ($t) => array_fill(0, 101, ['wallet_id' => $t->shared->id, 'member_user_id' => $t->pat->id]), '/successors', 'OUT_OF_RANGE'],
]);

test('a wrong choice never counts as a failed password attempt', function () {
    requestDeletionWith($this->credential, [['wallet_id' => $this->solo->id, 'member_user_id' => $this->pat->id]])->assertStatus(422);
    foreach (range(1, 6) as $attempt) {
        requestDeletionWith($this->credential, [['wallet_id' => $this->solo->id, 'member_user_id' => $this->pat->id]])->assertStatus(422);
    }

    requestDeletionWith($this->credential)->assertOk();
});

test('repeating with successors replaces the choices, without leaves them, and the date never moves', function () {
    $first = requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]])->assertOk();
    $this->travel(3)->days();

    $second = requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->quinn->id]])->assertOk();
    expect($second->json('data.deletion_scheduled_for'))->toBe($first->json('data.deletion_scheduled_for'))
        ->and(DB::table('deletion_successor_choices')->value('member_user_id'))->toBe($this->quinn->id);

    requestDeletionWith($this->credential)->assertOk();
    expect(DB::table('deletion_successor_choices')->value('member_user_id'))->toBe($this->quinn->id);

    requestDeletionWith($this->credential, [])->assertOk();
    expect(DB::table('deletion_successor_choices')->count())->toBe(0);
});

test('cancelling, explicitly or by signing in, drops the choices', function () {
    requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]])->assertOk();

    exportCall('DELETE', $this->credential, '/account/deletion-request')->assertNoContent();
    expect(DB::table('deletion_successor_choices')->count())->toBe(0);

    requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]])->assertOk();
    signInAs()->assertOk();

    expect(User::query()->find($this->user->id)->state)->toBe('active')
        ->and(DB::table('deletion_successor_choices')->count())->toBe(0);
});

test('a chosen member who has since left is gone from the plan: the one member left inherits', function () {
    requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]])->assertOk();
    WalletMember::query()->where('wallet_id', $this->shared->id)->where('user_id', $this->pat->id)->update(['deleted_at' => now()]);

    $entry = collect(planOf($this->credential)['successors'])->firstWhere('wallet_id', $this->shared->id);

    expect($entry['successor_user_id'])->toBe($this->quinn->id)->and($entry['reason'])->toBe('only_member')
        ->and($entry['choice_expected'])->toBeFalse();
});

test('a chosen member who has left falls back to the oldest editor while several remain', function () {
    $third = person('third@example.test');
    addMember($this->shared->id, $third->id, 'editor');
    requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]])->assertOk();
    WalletMember::query()->where('wallet_id', $this->shared->id)->where('user_id', $this->pat->id)->update(['deleted_at' => now()]);

    $entry = collect(planOf($this->credential)['successors'])->firstWhere('wallet_id', $this->shared->id);

    expect($entry['successor_user_id'])->toBe($this->quinn->id)->and($entry['reason'])->toBe('oldest_editor');
});

test('the preview is exactly what the purge will do', function () {
    requestDeletionWith($this->credential, [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]])->assertOk();
    $plan = collect(app(DeletionPlanner::class)->plan($this->user->id)->successors)->mapWithKeys(fn ($entry) => [$entry->walletId => $entry->successor->userId]);

    purgeAccountNow($this->user->id);

    foreach ($plan as $walletId => $successorId) {
        expect(app(MembershipQueryService::class)->ownerOf($walletId))->toBe($successorId);
    }
    expect($plan)->toHaveCount(2);
});
