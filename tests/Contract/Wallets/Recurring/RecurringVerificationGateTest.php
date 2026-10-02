<?php

use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use Illuminate\Support\Str;

/*
 * Every recurring write is behind the verification gate; reads are not (spec
 * 014, FR-029; security baseline C1, C2).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->user, $this->credential] = walletCaller('ada@example.test', verified: false);
    $this->wallet = ownedWallet($this->user->id);
    $rule = createRecurringRule($this->user->id, $this->wallet->id)->rule;
    $this->rid = $rule->id;
    $this->oid = occurrencesOf($rule->id)->first()->id;
});

test('an unverified person is 403 EMAIL_NOT_VERIFIED on every write', function (string $write) {
    $response = match ($write) {
        'put rule' => recurringRuleCall('PUT', $this->credential, $this->wallet->id, $this->rid, recurringRuleBody([], 1)),
        'delete rule' => recurringRuleCall('DELETE', $this->credential, $this->wallet->id, $this->rid, ['version' => 1]),
        'materialise' => recurringRuleCall('POST', $this->credential, $this->wallet->id, $this->rid, [], '/materialise'),
        'put occurrence' => occurrenceCall('PUT', $this->credential, $this->wallet->id, $this->oid, occurrenceBody($this->oid, ['status' => 'skipped'])),
    };

    $response->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
})->with(['put rule', 'delete rule', 'materialise', 'put occurrence']);

test('the gate answers before the id is judged and nothing is created', function () {
    recurringRuleCall('PUT', $this->credential, $this->wallet->id, 'not-an-id', recurringRuleBody())->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    recurringRuleCall('PUT', $this->credential, $this->wallet->id, (string) Str::uuid7(), recurringRuleBody())->assertStatus(403);

    expect(RecurringRule::query()->count())->toBe(1);
});

test('reads work for an unverified person', function () {
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/recurring-rules')->assertOk();
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/occurrences')->assertOk();
    recurringRuleCall('GET', $this->credential, $this->wallet->id, $this->rid)->assertOk();
    occurrenceCall('GET', $this->credential, $this->wallet->id, $this->oid)->assertOk();
});
