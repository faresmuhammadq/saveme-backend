<?php

use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;

/*
 * GET /wallets (spec 010, US1).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('lists only the callers live wallets, archived included, each with my_role', function () {
    $other = person('grace@example.test');
    $mine = ownedWallet($this->user->id, ['name' => 'Mine']);
    $archived = ownedWallet($this->user->id, ['name' => 'Old', 'archived_at' => now()]);
    ownedWallet($this->user->id, ['name' => 'Gone', 'deleted_at' => now()]);
    ownedWallet($other->id, ['name' => 'Hers']);
    $shared = ownedWallet($other->id, ['name' => 'Shared']);
    addMember($shared->id, $this->user->id, 'viewer');

    $response = walletCall('GET', $this->credential, '/wallets')->assertValidRequest()->assertValidResponse(200);

    $byName = collect($response->json('data'))->keyBy('name');
    expect($byName->keys()->sort()->values()->all())->toBe(['Mine', 'Old', 'Shared'])
        ->and($byName['Mine']['my_role'])->toBe('owner')
        ->and($byName['Shared']['my_role'])->toBe('viewer')
        ->and($byName['Old']['archived_at'])->not->toBeNull()
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null]);
});

test('with no wallets the answer is 200 with an empty list', function () {
    walletCall('GET', $this->credential, '/wallets')
        ->assertValidResponse(200)
        ->assertJsonPath('data', []);
});

test('sorting by name is in byte order, descending by created_at works', function () {
    ownedWallet($this->user->id, ['name' => 'b', 'created_at' => now()->subDays(2)]);
    ownedWallet($this->user->id, ['name' => 'B', 'created_at' => now()->subDays(1)]);
    ownedWallet($this->user->id, ['name' => 'a', 'created_at' => now()]);

    expect(collect(walletCall('GET', $this->credential, '/wallets?sort=name')->assertValidResponse(200)->json('data'))->pluck('name')->all())->toBe(['B', 'a', 'b']);
    expect(collect(walletCall('GET', $this->credential, '/wallets?sort=-created_at')->assertValidResponse(200)->json('data'))->pluck('name')->all())->toBe(['a', 'B', 'b']);
    expect(collect(walletCall('GET', $this->credential, '/wallets')->assertValidResponse(200)->json('data'))->pluck('name')->all())->toBe(['b', 'B', 'a']);
});

test('paging with limit and next_cursor visits every wallet once', function () {
    foreach (['a', 'b', 'c'] as $name) {
        ownedWallet($this->user->id, ['name' => $name]);
    }

    $first = walletCall('GET', $this->credential, '/wallets?limit=1&sort=name')->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue();

    $seen = [$first->json('data.0.name')];
    $cursor = $first->json('meta.next_cursor');
    while ($cursor !== null) {
        $page = walletCall('GET', $this->credential, '/wallets?limit=1&sort=name&cursor='.$cursor)->assertValidResponse(200);
        $seen[] = $page->json('data.0.name');
        $cursor = $page->json('meta.next_cursor');
    }

    expect($seen)->toBe(['a', 'b', 'c']);
});

test('unsupported filters and sorts are 400', function () {
    withoutSpectatorRequestValidation();

    walletCall('GET', $this->credential, '/wallets?filter[type]=cash')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, '/wallets?sort=balance_minor')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, '/wallets?cursor=garbage')->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    walletCall('GET', $this->credential, '/wallets?limit=0')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
});

test('it needs a credential', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/wallets')->assertValidResponse(401);
});

test('50 wallets cost a constant number of queries and never sum postings', function () {
    $queriesFor = function (): int {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        walletCall('GET', $this->credential, '/wallets?limit=200')->assertOk();

        return count(array_filter($queries, fn (string $sql): bool => ! str_contains($sql, 'personal_access_tokens') && ! str_contains($sql, 'users') && ! str_contains($sql, 'profiles') && ! str_contains($sql, 'device_sessions')));
    };

    Wallet::factory()->count(2)->ownedBy($this->user->id)->create();
    $few = $queriesFor();

    Wallet::factory()->count(48)->ownedBy($this->user->id)->create();
    $many = $queriesFor();

    expect($many)->toBe($few);
});
