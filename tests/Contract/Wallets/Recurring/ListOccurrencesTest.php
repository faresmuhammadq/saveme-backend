<?php

use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/occurrences (spec 014, FR-022): the live occurrences,
 * earliest date first, filtered by rule, status and date.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->category = ownedCategory($this->user->id);
    $this->ruleA = RecurringRule::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'created_by' => $this->user->id]);
    $this->ruleB = RecurringRule::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'created_by' => $this->user->id]);

    $make = fn (RecurringRule $rule, array $attributes) => Occurrence::factory()->forRule($rule)->create($attributes)->id;

    $this->o1 = $make($this->ruleA, ['scheduled_date' => '2026-11-01', 'date' => '2026-11-01', 'created_at' => now()->subDays(3)]);
    $this->o2 = $make($this->ruleA, ['scheduled_date' => '2026-12-01', 'date' => '2026-12-01', 'ordinal' => 2, 'status' => 'skipped', 'version' => 2, 'created_at' => now()->subDays(2)]);
    $this->o3 = $make($this->ruleB, ['scheduled_date' => '2026-11-15', 'date' => '2026-11-20', 'created_at' => now()->subDays(1)]);
    $make($this->ruleB, ['scheduled_date' => '2026-12-15', 'date' => '2026-12-15', 'ordinal' => 2, 'deleted_at' => now(), 'version' => 2]);
});

function listOccurrenceIds(string $credential, string $walletId, string $query = ''): array
{
    return collect(walletCall('GET', $credential, '/wallets/'.$walletId.'/occurrences'.$query)->assertOk()->json('data'))->pluck('id')->all();
}

test('lists the live occurrences earliest date first, tombstones left out', function () {
    $response = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/occurrences')->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.*.id'))->toBe([$this->o1, $this->o3, $this->o2])
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null, 'limit' => 50])
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'wallet_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'recurring_rule_id', 'ordinal', 'is_payout', 'scheduled_date', 'date', 'status', 'kind', 'amount_minor', 'currency', 'category_id', 'counter_wallet_id', 'description', 'memo', 'counterparty', 'edited', 'transaction_id', 'auto_confirm_refusal']);
});

test('sorts by created_at and by date descending', function () {
    $id = $this->wallet->id;

    expect(listOccurrenceIds($this->credential, $id, '?sort=created_at'))->toBe([$this->o1, $this->o2, $this->o3])
        ->and(listOccurrenceIds($this->credential, $id, '?sort=-date'))->toBe([$this->o2, $this->o3, $this->o1]);
});

test('filters by rule, eq and in, by status and by date with every operator', function () {
    withoutSpectatorRequestValidation();
    $id = $this->wallet->id;

    expect(listOccurrenceIds($this->credential, $id, '?filter[recurring_rule_id]='.$this->ruleA->id))->toBe([$this->o1, $this->o2])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[recurring_rule_id]=in:'.$this->ruleA->id.','.$this->ruleB->id))->toBe([$this->o1, $this->o3, $this->o2])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[status]=skipped'))->toBe([$this->o2])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[status]=in:scheduled,skipped'))->toBe([$this->o1, $this->o3, $this->o2])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[date]=2026-11-20'))->toBe([$this->o3])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[date]=gt:2026-11-01'))->toBe([$this->o3, $this->o2])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[date]=gte:2026-11-20'))->toBe([$this->o3, $this->o2])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[date]=lt:2026-11-20'))->toBe([$this->o1])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[date]=lte:2026-11-20'))->toBe([$this->o1, $this->o3])
        ->and(listOccurrenceIds($this->credential, $id, '?filter[date]=gte:2026-11-02&filter[date]=lte:2026-11-30'))->toBe([$this->o3]);
});

test('more than 50 rule ids is 400 INVALID_QUERY_PARAMETER', function () {
    withoutSpectatorRequestValidation();
    $ids = collect(range(1, 51))->map(fn () => (string) Str::uuid7())->implode(',');

    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/occurrences?filter[recurring_rule_id]=in:'.$ids)->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
});

test('refuses an unsupported field, operator and sort', function () {
    withoutSpectatorRequestValidation();
    $path = '/wallets/'.$this->wallet->id.'/occurrences';

    walletCall('GET', $this->credential, $path.'?filter[amount_minor]=1')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?filter[status]=gt:scheduled')->assertStatus(400)->assertJsonPath('code', 'FILTER_OPERATOR_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?filter[date]=ne:2026-11-01')->assertStatus(400)->assertJsonPath('code', 'FILTER_OPERATOR_NOT_SUPPORTED');
    walletCall('GET', $this->credential, $path.'?sort=amount_minor')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
});

test('a cursor reused with another filter is INVALID_CURSOR, a good one pages on', function () {
    withoutSpectatorRequestValidation();
    $path = '/wallets/'.$this->wallet->id.'/occurrences';

    $first = walletCall('GET', $this->credential, $path.'?limit=2')->assertOk();
    $second = walletCall('GET', $this->credential, $path.'?limit=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

    expect($first->json('data.*.id'))->toBe([$this->o1, $this->o3])->and($second->json('data.*.id'))->toBe([$this->o2]);

    walletCall('GET', $this->credential, $path.'?limit=2&filter[status]=scheduled&cursor='.$first->json('meta.next_cursor'))->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});
