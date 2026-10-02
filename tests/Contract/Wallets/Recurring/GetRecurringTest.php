<?php

use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/recurring-rules/{rid} and /occurrences/{oid} (spec 014,
 * FR-022), and the transaction links on a transaction read (FR-024).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->rule = createRecurringRule($this->user->id, $this->wallet->id)->rule;
    $this->occurrence = occurrencesOf($this->rule->id)->first();
});

test('reads a live rule and a live occurrence', function () {
    recurringRuleCall('GET', $this->credential, $this->wallet->id, $this->rule->id)->assertValidRequest()->assertValidResponse(200)->assertJsonPath('data.id', $this->rule->id);
    occurrenceCall('GET', $this->credential, $this->wallet->id, $this->occurrence->id)->assertValidRequest()->assertValidResponse(200)->assertJsonPath('data.recurring_rule_id', $this->rule->id);
});

test('reads a tombstoned rule and occurrence by id', function () {
    app(RecurringRuleService::class)->delete($this->user->id, $this->wallet->id, $this->rule->id, 1, presentRule());

    recurringRuleCall('GET', $this->credential, $this->wallet->id, $this->rule->id)->assertValidResponse(200)->assertJsonPath('data.deleted_at', fn ($value) => $value !== null);
    occurrenceCall('GET', $this->credential, $this->wallet->id, $this->occurrence->id)->assertValidResponse(200)->assertJsonPath('data.deleted_at', fn ($value) => $value !== null);
});

test('a foreign id, another wallet\'s record, a non-member and a deleted path wallet are 404, a malformed id 422', function () {
    $other = ownedWallet($this->user->id);
    $deleted = ownedWallet($this->user->id, ['deleted_at' => now()]);
    $stranger = person('stranger@example.test');
    $credential = signInAs('stranger@example.test')->json('data.credential');

    recurringRuleCall('GET', $this->credential, $this->wallet->id, (string) Str::uuid7())->assertValidResponse(404);
    occurrenceCall('GET', $this->credential, $this->wallet->id, (string) Str::uuid7())->assertValidResponse(404);
    recurringRuleCall('GET', $this->credential, $other->id, $this->rule->id)->assertValidResponse(404);
    occurrenceCall('GET', $this->credential, $other->id, $this->occurrence->id)->assertValidResponse(404);
    recurringRuleCall('GET', $credential, $this->wallet->id, $this->rule->id)->assertValidResponse(404);
    occurrenceCall('GET', $credential, $this->wallet->id, $this->occurrence->id)->assertValidResponse(404);
    recurringRuleCall('GET', $this->credential, $deleted->id, $this->rule->id)->assertValidResponse(404);
    recurringRuleCall('GET', $this->credential, $this->wallet->id, 'not-an-id')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    occurrenceCall('GET', $this->credential, $this->wallet->id, 'not-an-id')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');

    expect($stranger->id)->not->toBeNull();
});

test('an id held by a transaction is not a rule or an occurrence', function () {
    $transaction = recordTransaction($this->user->id, $this->wallet->id)->transaction;

    recurringRuleCall('GET', $this->credential, $this->wallet->id, $transaction->id)->assertValidResponse(404);
    occurrenceCall('GET', $this->credential, $this->wallet->id, $transaction->id)->assertValidResponse(404);
});

test('a transaction read shows the links of the occurrence that produced it, null for one recorded by hand', function () {
    $body = confirmBody($this->occurrence);
    occurrenceCall('PUT', $this->credential, $this->wallet->id, $this->occurrence->id, $body)->assertOk();
    $byHand = recordTransaction($this->user->id, $this->wallet->id)->transaction;

    transactionCall('GET', $this->credential, $this->wallet->id, $body['transaction_id'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.recurring_rule_id', $this->rule->id)
        ->assertJsonPath('data.occurrence_id', $this->occurrence->id);
    transactionCall('GET', $this->credential, $this->wallet->id, $byHand->id)
        ->assertValidResponse(200)
        ->assertJsonPath('data.recurring_rule_id', null)
        ->assertJsonPath('data.occurrence_id', null);
});
