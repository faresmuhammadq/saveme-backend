<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * POST /wallets/{id}/recurring-rules/{rid}/materialise (spec 014, US5; every
 * documented status of the operation).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->rule = createRecurringRule($this->user->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
});

test('200 returns the rule after catching up, and a second call changes nothing', function () {
    freezeClock('2026-10-14 08:00:00');

    $first = recurringRuleCall('POST', $this->credential, $this->wallet->id, $this->rule->id, [], '/materialise')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.id', $this->rule->id)
        ->assertJsonPath('data.version', 1);

    recurringRuleCall('POST', $this->credential, $this->wallet->id, $this->rule->id, [], '/materialise')->assertValidResponse(200);

    expect($first->json('meta'))->toBe([])->and(Transaction::query()->count())->toBe(2);
});

test('an unverified person is 403 EMAIL_NOT_VERIFIED', function () {
    [$user, $credential] = walletCaller('unverified@example.test', verified: false);
    $wallet = ownedWallet($user->id);

    recurringRuleCall('POST', $credential, $wallet->id, (string) Str::uuid7(), [], '/materialise')->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('a viewer is 403, a non-member and an unknown rule are 404, a malformed id is 422', function () {
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    $viewerCredential = signInAs('viewer@example.test')->json('data.credential');
    person('stranger@example.test');
    $stranger = signInAs('stranger@example.test')->json('data.credential');

    recurringRuleCall('POST', $viewerCredential, $this->wallet->id, $this->rule->id, [], '/materialise')->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
    recurringRuleCall('POST', $stranger, $this->wallet->id, $this->rule->id, [], '/materialise')->assertValidResponse(404);
    recurringRuleCall('POST', $this->credential, $this->wallet->id, (string) Str::uuid7(), [], '/materialise')->assertValidResponse(404);
    recurringRuleCall('POST', $this->credential, $this->wallet->id, 'not-an-id', [], '/materialise')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('a tombstone is 409 with the tombstone in current', function () {
    recurringRuleCall('DELETE', $this->credential, $this->wallet->id, $this->rule->id, ['version' => 1])->assertOk();

    recurringRuleCall('POST', $this->credential, $this->wallet->id, $this->rule->id, [], '/materialise')
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.deleted_at', fn ($value) => $value !== null);
});
