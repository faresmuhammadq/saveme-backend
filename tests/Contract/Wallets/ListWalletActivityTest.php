<?php

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * GET /wallets/{id}/activity (spec 016, US8; row S-07; ADR-0020): every
 * documented status.
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    $this->editor = person('editor@example.test');
    addMember($this->wallet->id, $this->editor->id, 'editor');
    $this->uri = '/wallets/'.$this->wallet->id.'/activity';
});

function activityEntry(string $walletId, array $attributes = []): WalletActivity
{
    return WalletActivity::query()->create([
        'id' => (string) Str::uuid7(),
        'wallet_id' => $walletId,
        'occurred_at' => now(),
        'actor_user_id' => null,
        'action' => 'created',
        'subject_type' => 'transaction',
        'subject_id' => (string) Str::uuid7(),
        'role' => null,
        ...$attributes,
    ]);
}

test('200 lists entries newest first with the actor named while they are a member', function () {
    Profile::query()->where('user_id', $this->editor->id)->update(['display_name_ciphertext' => app(AttributeCipher::class)->seal($this->editor->id, 'Karim')]);
    $older = activityEntry($this->wallet->id, ['occurred_at' => now()->subHour(), 'actor_user_id' => $this->editor->id, 'action' => 'joined', 'subject_type' => 'member', 'subject_id' => $this->editor->id, 'role' => 'editor']);
    $newer = activityEntry($this->wallet->id, ['occurred_at' => now(), 'actor_user_id' => $this->editor->id]);

    $response = walletCall('GET', $this->credential, $this->uri)->assertValidRequest()->assertValidResponse(200);

    expect(array_column($response->json('data'), 'id'))->toBe([$newer->id, $older->id])
        ->and($response->json('data.0'))->toMatchArray(['wallet_id' => $this->wallet->id, 'actor_user_id' => $this->editor->id, 'actor_display_name' => 'Karim', 'action' => 'created', 'subject_type' => 'transaction', 'role' => null])
        ->and($response->json('data.1'))->toMatchArray(['action' => 'joined', 'subject_type' => 'member', 'subject_id' => $this->editor->id, 'role' => 'editor'])
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'wallet_id', 'occurred_at', 'actor_user_id', 'actor_display_name', 'action', 'subject_type', 'subject_id', 'role'])
        ->and($response->json('meta'))->toMatchArray(['next_cursor' => null, 'has_more' => false, 'limit' => 50]);
});

test('the actor\'s name is null for a server act and for a former member', function () {
    Profile::query()->where('user_id', $this->editor->id)->update(['display_name_ciphertext' => app(AttributeCipher::class)->seal($this->editor->id, 'Karim')]);
    activityEntry($this->wallet->id, ['actor_user_id' => null, 'action' => 'confirmed', 'subject_type' => 'occurrence', 'occurred_at' => now()->subMinute()]);
    activityEntry($this->wallet->id, ['actor_user_id' => $this->editor->id]);
    WalletMember::query()->where('user_id', $this->editor->id)->update(['deleted_at' => now()]);

    $rows = walletCall('GET', $this->credential, $this->uri)->assertValidResponse(200)->json('data');

    expect($rows[0]['actor_user_id'])->toBe($this->editor->id)->and($rows[0]['actor_display_name'])->toBeNull()
        ->and($rows[1]['actor_user_id'])->toBeNull()->and($rows[1]['actor_display_name'])->toBeNull();
});

test('an empty wallet is 200 with no data; every member may read; another wallet\'s entries never appear', function () {
    expect(walletCall('GET', $this->credential, $this->uri)->assertValidResponse(200)->json('data'))->toBe([]);

    $other = ownedWallet($this->owner->id);
    activityEntry($other->id);
    activityEntry($this->wallet->id);
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');

    foreach ([$this->credential, signInAs('editor@example.test')->json('data.credential'), signInAs('viewer@example.test')->json('data.credential')] as $credential) {
        $data = walletCall('GET', $credential, $this->uri)->assertValidResponse(200)->json('data');
        expect($data)->toHaveCount(1)->and($data[0]['wallet_id'])->toBe($this->wallet->id);
    }
});

test('paging with limit visits every entry once, newest first', function () {
    $ids = [];
    foreach (range(1, 5) as $n) {
        $ids[] = activityEntry($this->wallet->id, ['occurred_at' => now()->subMinutes(10 - $n)])->id;
    }

    $seen = [];
    $cursor = null;
    do {
        $page = walletCall('GET', $this->credential, $this->uri.'?limit=2'.($cursor === null ? '' : '&cursor='.$cursor))->assertValidResponse(200);
        $seen = [...$seen, ...array_column($page->json('data'), 'id')];
        $cursor = $page->json('meta.next_cursor');
    } while ($page->json('meta.has_more'));

    expect($seen)->toBe(array_reverse($ids));
});

test('entries with the same instant are stabilised by id', function () {
    $at = now();
    $a = activityEntry($this->wallet->id, ['occurred_at' => $at]);
    $b = activityEntry($this->wallet->id, ['occurred_at' => $at]);
    $c = activityEntry($this->wallet->id, ['occurred_at' => $at]);

    $first = walletCall('GET', $this->credential, $this->uri.'?limit=2')->assertValidResponse(200);
    $second = walletCall('GET', $this->credential, $this->uri.'?limit=2&cursor='.$first->json('meta.next_cursor'))->assertValidResponse(200);

    expect([...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')])->toBe(collect([$a->id, $b->id, $c->id])->sortDesc()->values()->all());
});

test('400 for a filter, a sort or a forged cursor', function () {
    withoutSpectatorRequestValidation();

    walletCall('GET', $this->credential, $this->uri.'?filter[action]=created')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $this->uri.'?sort=action')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $this->uri.'?cursor=forged')->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    walletCall('GET', $this->credential, $this->uri.'?limit=0')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
});

test('404 for a non-member, an unknown wallet and a former member', function () {
    [, $stranger] = walletCaller('stranger@example.test');
    walletCall('GET', $stranger, $this->uri)->assertValidResponse(404);
    walletCall('GET', $this->credential, '/wallets/'.Str::uuid7().'/activity')->assertValidResponse(404);

    WalletMember::query()->where('user_id', $this->editor->id)->update(['deleted_at' => now()]);
    walletCall('GET', signInAs('editor@example.test')->json('data.credential'), $this->uri)->assertValidResponse(404);
});

test('422 INVALID_ID for a malformed wallet id', function () {
    walletCall('GET', $this->credential, '/wallets/not-an-id/activity')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('the shared refusals: unauthenticated, not acceptable, upgrade required', function () {
    assertSharedRefusals('GET', $this->uri, [], $this->credential);
});

test('a real flow shows through the endpoint: who did what, with no amount or name in the entry', function () {
    $editorCredential = signInAs('editor@example.test')->json('data.credential');
    $id = (string) Str::uuid7();
    walletCall('PUT', $editorCredential, '/wallets/'.$this->wallet->id.'/transactions/'.$id, transactionBody(['amount_minor' => 98765, 'description' => 'Secret lunch']))->assertCreated();

    $response = walletCall('GET', $this->credential, $this->uri)->assertValidResponse(200);

    expect($response->json('data.0'))->toMatchArray(['actor_user_id' => $this->editor->id, 'action' => 'created', 'subject_type' => 'transaction', 'subject_id' => $id])
        ->and($response->getContent())->not->toContain('98765')->not->toContain('Secret lunch');
});
