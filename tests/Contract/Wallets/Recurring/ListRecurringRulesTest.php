<?php

use App\Modules\Wallets\Infrastructure\Models\RecurringRule;

/*
 * GET /wallets/{id}/recurring-rules (spec 014, FR-022): every live status,
 * sorted by created_at or start_date, filtered by status.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->other = ownedWallet($this->user->id);
    $this->category = ownedCategory($this->user->id);

    $make = fn (array $attributes) => RecurringRule::factory()->create([
        'wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'created_by' => $this->user->id, ...$attributes,
    ])->id;

    $this->r1 = $make(['status' => 'active', 'start_date' => '2026-12-01', 'created_at' => now()->subDays(5)]);
    $this->r2 = $make(['status' => 'paused', 'start_date' => '2026-10-01', 'created_at' => now()->subDays(4)]);
    $this->r3 = $make(['status' => 'ended', 'start_date' => '2026-11-01', 'created_at' => now()->subDays(3)]);
    $make(['deleted_at' => now(), 'version' => 2]);
    RecurringRule::factory()->create(['wallet_id' => $this->other->id, 'category_id' => $this->category->id, 'created_by' => $this->user->id]);
});

function listRuleIds(string $credential, string $walletId, string $query = ''): array
{
    return collect(walletCall('GET', $credential, '/wallets/'.$walletId.'/recurring-rules'.$query)->assertOk()->json('data'))->pluck('id')->all();
}

test('lists every live status, tombstones and other wallets\' rules left out, by created_at', function () {
    $response = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/recurring-rules')->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.*.id'))->toBe([$this->r1, $this->r2, $this->r3])
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null, 'limit' => 50])
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'wallet_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'preset', 'status', 'kind', 'amount_minor', 'amount_basis', 'currency', 'category_id', 'counter_wallet_id', 'description', 'memo', 'counterparty', 'auto_confirm', 'frequency', 'interval', 'anchor_day', 'start_date', 'end_date', 'occurrence_count', 'payout_round', 'payout_category_id', 'shared_budget_id', 'timezone', 'created_by']);
});

test('sorts by start_date, either way, and by created_at descending', function () {
    $ascending = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/recurring-rules?sort=start_date')->assertValidRequest()->assertValidResponse(200);

    expect($ascending->json('data.*.id'))->toBe([$this->r2, $this->r3, $this->r1])
        ->and(listRuleIds($this->credential, $this->wallet->id, '?sort=-start_date'))->toBe([$this->r1, $this->r3, $this->r2])
        ->and(listRuleIds($this->credential, $this->wallet->id, '?sort=-created_at'))->toBe([$this->r3, $this->r2, $this->r1]);
});

test('filters by status, eq and in', function () {
    withoutSpectatorRequestValidation();
    $id = $this->wallet->id;

    expect(listRuleIds($this->credential, $id, '?filter[status]=paused'))->toBe([$this->r2])
        ->and(listRuleIds($this->credential, $id, '?filter[status]=eq:ended'))->toBe([$this->r3])
        ->and(listRuleIds($this->credential, $id, '?filter[status]=in:active,ended'))->toBe([$this->r1, $this->r3]);
});

test('pages with a cursor and refuses it with another filter', function () {
    withoutSpectatorRequestValidation();
    $path = '/wallets/'.$this->wallet->id.'/recurring-rules';

    $first = walletCall('GET', $this->credential, $path.'?limit=2')->assertValidResponse(200);
    $second = walletCall('GET', $this->credential, $path.'?limit=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

    expect($first->json('data.*.id'))->toBe([$this->r1, $this->r2])->and($first->json('meta.has_more'))->toBeTrue()
        ->and($second->json('data.*.id'))->toBe([$this->r3]);

    walletCall('GET', $this->credential, $path.'?limit=2&filter[status]=active&cursor='.$first->json('meta.next_cursor'))->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('refuses an unsupported filter field, operator and sort, and a bad status', function () {
    withoutSpectatorRequestValidation();
    $path = '/wallets/'.$this->wallet->id.'/recurring-rules';

    walletCall('GET', $this->credential, $path.'?filter[kind]=expense')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?filter[status]=gt:active')->assertStatus(400)->assertJsonPath('code', 'FILTER_OPERATOR_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?sort=amount_minor')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?filter[status]=done')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
});
