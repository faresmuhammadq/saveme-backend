<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/transactions (spec 012, US4; FR-017 to FR-020, R-11).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->other = ownedWallet($this->user->id);
    $this->food = ownedCategory($this->user->id, ['name' => 'Food']);
    $this->pay = ownedCategory($this->user->id, ['name' => 'Pay', 'kind' => 'income']);

    $make = fn (array $attributes) => Transaction::factory()->create([
        'wallet_id' => $this->wallet->id, 'category_id' => $this->food->id, 'created_by' => $this->user->id, ...$attributes,
    ])->id;

    $this->t1 = $make(['date' => '2026-09-01', 'description' => 'Coffee beans', 'created_at' => now()->subDays(5)]);
    $this->t2 = $make(['date' => '2026-09-15', 'description' => 'Lunch', 'memo' => 'with Ahmed', 'created_at' => now()->subDays(4)]);
    $this->t3 = $make(['date' => '2026-09-20', 'kind' => 'income', 'category_id' => $this->pay->id, 'description' => 'Salary', 'counterparty' => 'ACME', 'created_at' => now()->subDays(3)]);
    $this->t4 = $make(['wallet_id' => $this->other->id, 'kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $this->wallet->id, 'counter_change_seq' => 1, 'date' => '2026-09-25', 'description' => 'Top up', 'created_at' => now()->subDays(2)]);
    $this->t5 = $make(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $this->other->id, 'counter_change_seq' => 1, 'date' => '2026-10-01', 'description' => 'Move', 'created_at' => now()->subDays(1)]);
    $make(['date' => '2026-10-02', 'description' => 'Deleted', 'deleted_at' => now(), 'version' => 2]);
    $make(['wallet_id' => $this->other->id, 'date' => '2026-10-02', 'description' => 'Elsewhere']);
});

function listIds(string $credential, string $walletId, string $query = ''): array
{
    return collect(walletCall('GET', $credential, '/wallets/'.$walletId.'/transactions'.$query)->assertOk()->json('data'))->pluck('id')->all();
}

test('lists the live transactions, transfers into the wallet included, newest date first', function () {
    $response = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions')->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.*.id'))->toBe([$this->t5, $this->t4, $this->t3, $this->t2, $this->t1])
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null, 'limit' => 50])
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'wallet_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'kind', 'date', 'amount_minor', 'currency', 'category_id', 'counter_wallet_id', 'description', 'memo', 'counterparty', 'cleared', 'created_by', 'recurring_rule_id', 'occurrence_id', 'shared_budget_id']);
});

test('the other wallet lists the same transfers, the destination side included', function () {
    expect(listIds($this->credential, $this->other->id))->toContain($this->t4, $this->t5)->not->toContain($this->t1);
});

test('sorting by created_at, either way, is stable', function () {
    $ascending = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions?sort=created_at')->assertValidRequest()->assertValidResponse(200);

    expect($ascending->json('data.*.id'))->toBe([$this->t1, $this->t2, $this->t3, $this->t4, $this->t5])
        ->and(listIds($this->credential, $this->wallet->id, '?sort=-created_at'))->toBe([$this->t5, $this->t4, $this->t3, $this->t2, $this->t1])
        ->and(listIds($this->credential, $this->wallet->id, '?sort=date'))->toBe([$this->t1, $this->t2, $this->t3, $this->t4, $this->t5]);
});

test('filters by date with every operator', function () {
    withoutSpectatorRequestValidation();
    $id = $this->wallet->id;

    expect(listIds($this->credential, $id, '?filter[date]=2026-09-15'))->toBe([$this->t2])
        ->and(listIds($this->credential, $id, '?filter[date]=eq:2026-09-15'))->toBe([$this->t2])
        ->and(listIds($this->credential, $id, '?filter[date]=gt:2026-09-20'))->toBe([$this->t5, $this->t4])
        ->and(listIds($this->credential, $id, '?filter[date]=gte:2026-09-20'))->toBe([$this->t5, $this->t4, $this->t3])
        ->and(listIds($this->credential, $id, '?filter[date]=lt:2026-09-15'))->toBe([$this->t1])
        ->and(listIds($this->credential, $id, '?filter[date]=lte:2026-09-15'))->toBe([$this->t2, $this->t1])
        ->and(listIds($this->credential, $id, '?filter[date][]=gte:2026-09-15&filter[date][]=lt:2026-09-25'))->toBe([$this->t3, $this->t2]);
});

test('a repeated filter field ANDs its conditions, as api-conventions writes it', function () {
    withoutSpectatorRequestValidation();
    $id = $this->wallet->id;

    expect(listIds($this->credential, $id, '?filter[date]=gte:2026-09-15&filter[date]=lt:2026-09-25'))->toBe([$this->t3, $this->t2])
        ->and(listIds($this->credential, $id, '?filter%5Bdate%5D=gte:2026-09-15&filter%5Bdate%5D=lt:2026-09-25'))->toBe([$this->t3, $this->t2])
        ->and(listIds($this->credential, $id, '?filter[kind]=expense&filter[kind]=income'))->toBe([]);
});

test('filters by category and by kind, eq and in', function () {
    withoutSpectatorRequestValidation();
    $id = $this->wallet->id;

    expect(listIds($this->credential, $id, '?filter[category_id]='.$this->pay->id))->toBe([$this->t3])
        ->and(listIds($this->credential, $id, '?filter[category_id]=in:'.$this->pay->id.','.$this->food->id))->toBe([$this->t3, $this->t2, $this->t1])
        ->and(listIds($this->credential, $id, '?filter[kind]=transfer'))->toBe([$this->t5, $this->t4])
        ->and(listIds($this->credential, $id, '?filter[kind]=in:income,expense'))->toBe([$this->t3, $this->t2, $this->t1])
        ->and(listIds($this->credential, $id, '?filter[kind]=expense&filter[date]=gte:2026-09-10'))->toBe([$this->t2]);
});

test('search is a literal, case-insensitive substring of description, memo or counterparty', function () {
    $id = $this->wallet->id;

    expect(listIds($this->credential, $id, '?search=COFFEE'))->toBe([$this->t1])
        ->and(listIds($this->credential, $id, '?search=ahmed'))->toBe([$this->t2])
        ->and(listIds($this->credential, $id, '?search=acme'))->toBe([$this->t3])
        ->and(listIds($this->credential, $id, '?search=zzz'))->toBe([])
        ->and(listIds($this->credential, $id, '?search=%20lunch%20'))->toBe([$this->t2]);
});

test('percent and underscore in a search match themselves', function () {
    $percent = Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->food->id, 'created_by' => $this->user->id, 'description' => 'Save 100% now']);
    $underscore = Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->food->id, 'created_by' => $this->user->id, 'description' => 'snake_case']);
    Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->food->id, 'created_by' => $this->user->id, 'description' => 'snakeXcase']);

    expect(listIds($this->credential, $this->wallet->id, '?search=%25'))->toBe([$percent->id])
        ->and(listIds($this->credential, $this->wallet->id, '?search=_'))->toBe([$underscore->id])
        ->and(listIds($this->credential, $this->wallet->id, '?search=snake_'))->toBe([$underscore->id]);
});

test('a blank or overlong search is 400 INVALID_QUERY_PARAMETER', function () {
    $uri = '/wallets/'.$this->wallet->id.'/transactions';

    walletCall('GET', $this->credential, $uri.'?search=')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    walletCall('GET', $this->credential, $uri.'?search=%20%20')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    walletCall('GET', $this->credential, $uri.'?search='.str_repeat('a', 101))->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    walletCall('GET', $this->credential, $uri.'?search='.str_repeat('a', 100))->assertOk();
});

test('unsupported filters, operators and sorts, and bad values, are the matching 400', function (string $query, string $code) {
    withoutSpectatorRequestValidation();

    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions'.$query)->assertStatus(400)->assertJsonPath('code', $code);
})->with([
    'unsupported field' => ['?filter[amount_minor]=5', 'FILTER_FIELD_NOT_SUPPORTED'],
    'unsupported operator on kind' => ['?filter[kind]=ne:income', 'FILTER_OPERATOR_NOT_SUPPORTED'],
    'in on date' => ['?filter[date]=in:2026-09-01,2026-09-02', 'FILTER_OPERATOR_NOT_SUPPORTED'],
    'unsupported sort' => ['?sort=amount_minor', 'SORT_FIELD_NOT_SUPPORTED'],
    'two sort fields' => ['?sort=date,created_at', 'SORT_FIELD_NOT_SUPPORTED'],
    'a date that is not a date' => ['?filter[date]=2026-02-30', 'INVALID_QUERY_PARAMETER'],
    'a kind that is not a kind' => ['?filter[kind]=refund', 'INVALID_QUERY_PARAMETER'],
    'a category that is not an id' => ['?filter[category_id]=food', 'INVALID_QUERY_PARAMETER'],
    'limit zero' => ['?limit=0', 'INVALID_QUERY_PARAMETER'],
    'garbage cursor' => ['?cursor=garbage', 'INVALID_CURSOR'],
]);

test('fifty category ids are accepted and fifty-one is 400', function () {
    withoutSpectatorRequestValidation();
    $ids = fn (int $count): string => implode(',', array_map(fn () => (string) Str::uuid7(), range(1, $count)));

    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions?filter[category_id]=in:'.$ids(50))->assertOk();
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions?filter[category_id]=in:'.$ids(51))->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
});

test('paging with a limit visits every transaction once, with no skip or repeat on equal dates', function () {
    foreach (range(1, 4) as $index) {
        Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->food->id, 'created_by' => $this->user->id, 'date' => '2026-09-15', 'description' => 'tie '.$index]);
    }

    foreach (['', '?sort=created_at', '?sort=-date', '?sort=date'] as $sort) {
        $seen = [];
        $query = ($sort === '' ? '?' : $sort.'&').'limit=2';
        $page = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions'.$query)->assertOk();
        array_push($seen, ...$page->json('data.*.id'));

        while ($page->json('meta.has_more')) {
            $page = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions'.$query.'&cursor='.$page->json('meta.next_cursor'))->assertOk();
            array_push($seen, ...$page->json('data.*.id'));
        }

        expect($seen)->toHaveCount(9)->and(array_unique($seen))->toHaveCount(9);
    }
});

test('a cursor is valid only with the filters, sort and search that issued it', function () {
    withoutSpectatorRequestValidation();
    $uri = '/wallets/'.$this->wallet->id.'/transactions';
    $cursor = walletCall('GET', $this->credential, $uri.'?limit=1&search=a')->assertOk()->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    walletCall('GET', $this->credential, $uri.'?limit=1&search=a&cursor='.$cursor)->assertOk();
    walletCall('GET', $this->credential, $uri.'?limit=1&search=b&cursor='.$cursor)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    walletCall('GET', $this->credential, $uri.'?limit=1&cursor='.$cursor)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    walletCall('GET', $this->credential, $uri.'?limit=1&search=a&filter[kind]=expense&cursor='.$cursor)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    walletCall('GET', $this->credential, $uri.'?limit=1&search=a&sort=created_at&cursor='.$cursor)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('a forged cursor position is 400 INVALID_CURSOR, not a server error', function () {
    withoutSpectatorRequestValidation();
    $uri = '/wallets/'.$this->wallet->id.'/transactions';
    $good = walletCall('GET', $this->credential, $uri.'?limit=1')->assertOk()->json('meta.next_cursor');
    $decoded = json_decode((string) base64_decode(strtr($good, '-_', '+/')), true);
    $decoded['p'] = ['value' => 'not a date', 'id' => (string) Str::uuid7()];
    $forged = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');

    walletCall('GET', $this->credential, $uri.'?limit=1&cursor='.$forged)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('a non-member and a deleted wallet are 404, nothing is 200 with an empty list', function () {
    $stranger = person('grace@example.test');
    $theirs = ownedWallet($stranger->id);
    $empty = ownedWallet($this->user->id);

    walletCall('GET', $this->credential, '/wallets/'.$theirs->id.'/transactions')->assertValidResponse(404);
    walletCall('GET', $this->credential, '/wallets/'.$empty->id.'/transactions')->assertValidResponse(200)->assertJsonPath('data', []);
    walletCall('DELETE', $this->credential, '/wallets/'.$empty->id, ['version' => 1])->assertOk();
    walletCall('GET', $this->credential, '/wallets/'.$empty->id.'/transactions')->assertValidResponse(404);
});

test('it needs a credential and a UUIDv7 wallet id', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/wallets/'.$this->wallet->id.'/transactions')->assertValidResponse(401);
    walletCall('GET', $this->credential, '/wallets/nope/transactions')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});
