<?php

use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PUT /wallets/{id}/occurrences/{oid} (spec 014, US2; every documented status
 * of the operation). A client can never create an occurrence.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->rule = createRecurringRule($this->user->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    $this->occurrence = occurrencesOf($this->rule->id)->first();
});

function putOccurrence(string $credential, string $walletId, string $id, array $body): TestResponse
{
    return occurrenceCall('PUT', $credential, $walletId, $id, $body);
}

test('confirming at the amount actually paid is 200, creates the transaction with the same id and returns the confirmed occurrence', function () {
    $body = confirmBody($this->occurrence, ['amount_minor' => 61200, 'date' => '2026-11-03', 'description' => 'Electricity']);

    $response = putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, $body)
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray([
        'id' => $this->occurrence->id,
        'status' => 'confirmed',
        'version' => 2,
        'amount_minor' => 61200,
        'edited' => true,
        'transaction_id' => $body['transaction_id'],
        'auto_confirm_refusal' => null,
        'deleted_at' => null,
    ])->and($response->json('meta'))->toBe([])
        ->and(Transaction::query()->findOrFail($body['transaction_id'])->occurrence_id)->toBe($this->occurrence->id);
});

test('skipping and an identical replay are 200', function () {
    $body = occurrenceBody($this->occurrence, ['status' => 'skipped']);

    $first = putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, $body)->assertValidRequest()->assertValidResponse(200)->assertJsonPath('data.status', 'skipped');
    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, $body)
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.change_seq', $first->json('data.change_seq'));
});

test('an unknown id, another wallet\'s occurrence and a non-member are 404: a client cannot create an occurrence', function () {
    $body = occurrenceBody($this->occurrence);
    $before = Occurrence::query()->count();

    putOccurrence($this->credential, $this->wallet->id, (string) Str::uuid7(), $body)->assertValidResponse(404);
    putOccurrence($this->credential, ownedWallet($this->user->id)->id, $this->occurrence->id, $body)->assertValidResponse(404);

    $stranger = person('stranger@example.test');
    $credential = signInAs('stranger@example.test')->json('data.credential');
    putOccurrence($credential, $this->wallet->id, $this->occurrence->id, $body)->assertValidResponse(404);

    expect(Occurrence::query()->count())->toBe($before)->and($stranger->id)->not->toBeNull();
});

test('a viewer is 403', function () {
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    $credential = signInAs('viewer@example.test')->json('data.credential');

    putOccurrence($credential, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['status' => 'skipped']))->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
});

test('a stale version is 409 with the stored occurrence in current, and so is a tombstone', function () {
    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['amount_minor' => 70000]))->assertOk();

    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, [...occurrenceBody($this->occurrence), 'amount_minor' => 80000, 'version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.amount_minor', 70000)
        ->assertJsonPath('current.version', 2);

    Occurrence::query()->whereKey($this->occurrence->id)->update(['deleted_at' => now(), 'version' => 3]);

    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, [...occurrenceBody($this->occurrence), 'version' => 3])
        ->assertValidResponse(409)
        ->assertJsonPath('current.deleted_at', fn ($value) => $value !== null);
});

test('a confirmation without a transaction id is REQUIRED at /transaction_id; one outside a confirmation is INVALID_ENUM_VALUE', function () {
    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['status' => 'confirmed']))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/transaction_id')
        ->assertJsonPath('errors.0.code', 'REQUIRED');

    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['transaction_id' => (string) Str::uuid7()]))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/transaction_id')
        ->assertJsonPath('errors.0.code', 'INVALID_ENUM_VALUE');
});

test('any change to a confirmed occurrence is IMMUTABLE_FIELD at the member', function () {
    $body = confirmBody($this->occurrence);
    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, $body)->assertOk();

    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, [...$body, 'amount_minor' => 1, 'version' => 2])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/amount_minor')
        ->assertJsonPath('errors.0.code', 'IMMUTABLE_FIELD');
});

test('server-owned members are UNKNOWN_FIELD', function (string $member) {
    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, [...occurrenceBody($this->occurrence), $member => 'x'])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/'.$member)
        ->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');
})->with(['kind', 'scheduled_date', 'edited']);

test('version 0 is OUT_OF_RANGE and a malformed id is INVALID_ID', function () {
    putOccurrence($this->credential, $this->wallet->id, $this->occurrence->id, [...occurrenceBody($this->occurrence), 'version' => 0])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/version')
        ->assertJsonPath('errors.0.code', 'OUT_OF_RANGE');

    putOccurrence($this->credential, $this->wallet->id, 'not-an-id', [])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('an occurrence has no DELETE: 405 with Allow', function () {
    occurrenceCall('DELETE', $this->credential, $this->wallet->id, $this->occurrence->id, ['version' => 1])
        ->assertStatus(405)
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')
        ->assertHeader('Allow');
});
