<?php

use Illuminate\Support\Str;

/*
 * DELETE /wallets/{id}/recurring-rules/{rid} (spec 014, US3; every documented
 * status of the operation).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->rule = createRecurringRule($this->user->id, $this->wallet->id)->rule;
});

test('deleting is 200 with the tombstone, and so is deleting it again', function () {
    $first = recurringRuleCall('DELETE', $this->credential, $this->wallet->id, $this->rule->id, ['version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.deleted_at', fn ($value) => $value !== null);

    recurringRuleCall('DELETE', $this->credential, $this->wallet->id, $this->rule->id, ['version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.change_seq', $first->json('data.change_seq'));

    expect(occurrencesOf($this->rule->id))->toHaveCount(0);
});

test('a stale version is 409 with the stored rule', function () {
    recurringRuleCall('DELETE', $this->credential, $this->wallet->id, $this->rule->id, ['version' => 3])
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.version', 1);
});

test('a missing version is 422 REQUIRED at /version', function () {
    recurringRuleCall('DELETE', $this->credential, $this->wallet->id, $this->rule->id)
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/version')
        ->assertJsonPath('errors.0.code', 'REQUIRED');
});

test('an unknown rule and a non-member are 404', function () {
    recurringRuleCall('DELETE', $this->credential, $this->wallet->id, (string) Str::uuid7(), ['version' => 1])->assertValidResponse(404);

    $stranger = person('stranger@example.test');
    $credential = signInAs('stranger@example.test')->json('data.credential');
    recurringRuleCall('DELETE', $credential, $this->wallet->id, $this->rule->id, ['version' => 1])->assertValidResponse(404);

    expect($stranger->id)->not->toBeNull();
});

test('a viewer is 403', function () {
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    $credential = signInAs('viewer@example.test')->json('data.credential');

    recurringRuleCall('DELETE', $credential, $this->wallet->id, $this->rule->id, ['version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
});
