<?php

/*
 * GET /me/entitlements (spec 009, US1).
 */

beforeEach(function () {
    person();
    $this->signIn = signInAs()->json('data');
});

test('a new person reads the free plan, in their language, with no plan code', function () {
    $response = $this->withHeaders(asCredential($this->signIn['credential']))->get('/api/v1/me/entitlements')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.plan.name', 'Free');

    expect($response->json('data.features')['sharing.enabled'])->toBeFalse()
        ->and($response->getContent())->not->toContain('"code"');
    $this->flushHeaders();

    $this->withHeaders([...asCredential($this->signIn['credential']), 'Accept-Language' => 'ar'])->get('/api/v1/me/entitlements')
        ->assertJsonPath('data.plan.name', 'مجاني');
});

test('the sign-in response reports the same summary', function () {
    $read = $this->withHeaders(asCredential($this->signIn['credential']))->get('/api/v1/me/entitlements')->json('data');

    expect($this->signIn['entitlements'])->toEqual($read);
});

test('entitlements need a credential', function () {
    $this->withHeaders(jsonHeaders())->get('/api/v1/me/entitlements')
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
