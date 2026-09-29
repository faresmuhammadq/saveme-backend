<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * GET /me/subscription (spec 009, US3).
 */

beforeEach(function () {
    person();
    $this->credential = signInAs()->json('data.credential');
    $this->userId = User::query()->sole()->id;
});

test('with no subscription it is the free plan and nulls', function () {
    $this->withHeaders(asCredential($this->credential))->get('/api/v1/me/subscription')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.plan.name', 'Free')
        ->assertJsonPath('data.provider', null)
        ->assertJsonPath('data.status', null)
        ->assertJsonPath('data.current_period_end', null)
        ->assertJsonPath('data.auto_renew', null);
});

test('an active manual Plus subscription shows its channel, status and UTC instants', function () {
    Subscription::factory()->create(['user_id' => $this->userId]);

    $response = $this->withHeaders(asCredential($this->credential))->get('/api/v1/me/subscription')
        ->assertValidResponse(200)
        ->assertJsonPath('data.plan.name', 'Plus · Monthly')
        ->assertJsonPath('data.provider', 'manual')
        ->assertJsonPath('data.status', 'active');

    expect($response->json('data.current_period_end'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
});

test('in grace it says so, with the grace end', function () {
    Subscription::factory()->grace()->create(['user_id' => $this->userId]);

    $response = $this->withHeaders(asCredential($this->credential))->get('/api/v1/me/subscription')
        ->assertValidResponse(200)
        ->assertJsonPath('data.status', 'grace');

    expect($response->json('data.grace_until'))->not->toBeNull();
});

test('it needs a credential', function () {
    $this->withHeaders(jsonHeaders())->get('/api/v1/me/subscription')
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
