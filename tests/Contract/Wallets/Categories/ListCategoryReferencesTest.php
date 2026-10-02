<?php

use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use Illuminate\Support\Facades\DB;

/*
 * GET /wallets/{id}/category-references (spec 011, US4; permission row T-01).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->partner = person('grace@example.test');
    addMember($this->wallet->id, $this->partner->id, 'editor');
    $this->coffee = ownedCategory($this->partner->id, ['name' => 'Coffee', 'icon' => 'cup', 'color' => '#6D4C41']);
    $this->tea = ownedCategory($this->partner->id, ['name' => 'Tea']);
    app(CategoryReferences::class)->publish($this->wallet->id, $this->coffee->id);
    app(CategoryReferences::class)->publish($this->wallet->id, $this->tea->id);
});

function referencesCall(string $credential, string $walletId, string $query = '')
{
    return walletCall('GET', $credential, '/wallets/'.$walletId.'/category-references'.$query);
}

test('a member reads the live references in publication order', function () {
    $response = referencesCall($this->credential, $this->wallet->id)
        ->assertValidRequest()
        ->assertValidResponse(200);

    $data = $response->json('data');
    expect($data)->toHaveCount(2)
        ->and(array_column($data, 'name'))->toBe(['Coffee', 'Tea'])
        ->and($data[0])->toMatchArray([
            'wallet_id' => $this->wallet->id,
            'category_id' => $this->coffee->id,
            'owner_user_id' => $this->partner->id,
            'kind' => 'expense',
            'icon' => 'cup',
            'color' => '#6D4C41',
            'version' => 1,
            'deleted_at' => null,
        ])
        ->and(array_keys($data[0]))->toBe(['id', 'wallet_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'category_id', 'owner_user_id', 'kind', 'name', 'icon', 'color'])
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null]);
});

test('the owner reads above; an editor and a viewer may read too', function (string $role) {
    $member = person($role.'@example.test');
    addMember($this->wallet->id, $member->id, $role);
    $credential = signInAs($role.'@example.test')->json('data.credential');

    referencesCall($credential, $this->wallet->id)->assertValidResponse(200)->assertJsonCount(2, 'data');
})->with(['editor', 'viewer']);

test('a withdrawn reference is not listed', function () {
    CategoryReference::query()->where('category_id', $this->tea->id)->update(['deleted_at' => now()]);

    expect(referencesCall($this->credential, $this->wallet->id)->assertValidResponse(200)->json('data'))->toHaveCount(1);
});

test('paging with limit visits every reference once', function () {
    $first = referencesCall($this->credential, $this->wallet->id, '?limit=1')->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue();

    $second = referencesCall($this->credential, $this->wallet->id, '?limit=1&cursor='.$first->json('meta.next_cursor'))->assertValidResponse(200);

    expect($first->json('data.0.name'))->toBe('Coffee')
        ->and($second->json('data.0.name'))->toBe('Tea')
        ->and($second->json('meta.has_more'))->toBeFalse();
});

test('a non-member is 404', function () {
    [, $outsider] = walletCaller('stranger@example.test');

    referencesCall($outsider, $this->wallet->id)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
});

test('everyone, a former member included, is 404 once the wallet is deleted', function () {
    app(WalletService::class)->delete($this->user->id, $this->wallet->id, 1, presentWallet());

    referencesCall($this->credential, $this->wallet->id)->assertValidResponse(404);
    $partnerCredential = signInAs('grace@example.test')->json('data.credential');
    referencesCall($partnerCredential, $this->wallet->id)->assertValidResponse(404);
});

test('an empty wallet is 200 with an empty list', function () {
    $empty = ownedWallet($this->user->id);

    referencesCall($this->credential, $empty->id)->assertValidResponse(200)->assertJsonPath('data', []);
});

test('collection errors', function () {
    withoutSpectatorRequestValidation();

    referencesCall($this->credential, 'not-an-id')->assertStatus(422)->assertJsonPath('code', 'INVALID_ID');
    referencesCall($this->credential, $this->wallet->id, '?cursor=garbage')->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    referencesCall($this->credential, $this->wallet->id, '?limit=0')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    referencesCall($this->credential, $this->wallet->id, '?sort=name')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    referencesCall($this->credential, $this->wallet->id, '?filter[kind]=income')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
});

test('nobody writes a reference: any other method is 405 with Allow GET', function (string $method) {
    $response = walletCall($method, $this->credential, '/wallets/'.$this->wallet->id.'/category-references', ['name' => 'X'])
        ->assertStatus(405)
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');

    expect($response->headers->get('Allow'))->toContain('GET');
    expect(CategoryReference::query()->count())->toBe(2);
})->with(['PUT', 'POST', 'PATCH', 'DELETE']);

test('it needs a credential', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/wallets/'.$this->wallet->id.'/category-references')->assertValidResponse(401);
});

test('the read is a constant number of queries', function () {
    $count = function (): int {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        referencesCall($this->credential, $this->wallet->id, '?limit=200')->assertOk();

        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'category_references')));
    };

    $few = $count();
    foreach (range(1, 5) as $ignored) {
        app(CategoryReferences::class)->publish($this->wallet->id, ownedCategory($this->partner->id)->id);
    }

    expect($count())->toBe($few);
});
