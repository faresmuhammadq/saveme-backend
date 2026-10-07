<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PlayPushSigner;

/*
 * POST /webhooks/google-play against docs/openapi/paths/google-play.yaml
 * (spec 024, US4). Called by Google: no client headers, no session.
 */

beforeEach(function () {
    Queue::fake();
    $this->signer = PlayPushSigner::make();
    $this->signer->serveCertificates();
    $this->push = pubsubPush(subscriptionNotification(playToken('hook')), 'contract-msg-1');
});

test('204: verified and stored, with no client headers', function () {
    $response = $this->postJson('/api/v1/webhooks/google-play', $this->push, ['Authorization' => 'Bearer '.$this->signer->token()])
        ->assertValidRequest()->assertValidResponse(204);

    expect($response->getContent())->toBe('')->and($response->headers->get('X-Request-Id'))->not->toBeNull();
});

test('204: a repeated message id, a foreign subscription, a test notification', function () {
    $bearer = ['Authorization' => 'Bearer '.$this->signer->token()];

    $this->postJson('/api/v1/webhooks/google-play', $this->push, $bearer)->assertValidResponse(204);
    $this->postJson('/api/v1/webhooks/google-play', $this->push, $bearer)->assertValidResponse(204);
    $this->postJson('/api/v1/webhooks/google-play', pubsubPush(['testNotification' => ['version' => '1.0']], 'contract-msg-2'), $bearer)->assertValidRequest()->assertValidResponse(204);
    $this->postJson('/api/v1/webhooks/google-play', pubsubPush(subscriptionNotification('x'), 'contract-msg-3', 'projects/other/subscriptions/x'), $bearer)->assertValidResponse(204);
});

test('400: a body over 64 KiB or not the Pub/Sub shape', function () {
    $this->push['message']['attributes'] = ['padding' => str_repeat('x', 70000)];
    $bearer = ['Authorization' => 'Bearer '.$this->signer->token()];

    $this->postJson('/api/v1/webhooks/google-play', $this->push, $bearer)->assertValidResponse(400)->assertJsonPath('code', 'MALFORMED_JSON');
    $this->postJson('/api/v1/webhooks/google-play', ['hello' => 'world'], $bearer)->assertValidResponse(400)->assertJsonPath('code', 'MALFORMED_JSON');
});

test('401: PROVIDER_SIGNATURE_INVALID for a missing or a forged token', function () {
    $this->postJson('/api/v1/webhooks/google-play', $this->push)->assertValidResponse(401)->assertJsonPath('code', 'PROVIDER_SIGNATURE_INVALID');
    $this->postJson('/api/v1/webhooks/google-play', $this->push, ['Authorization' => 'Bearer '.$this->signer->forgedToken()])->assertValidResponse(401);
});

test('415: not declared as JSON', function () {
    $this->call('POST', '/api/v1/webhooks/google-play', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->signer->token()], 'x=1')->assertValidResponse(415);
});

test('503: Google\'s certificates cannot be obtained, so Pub/Sub retries', function () {
    Http::swap(new Factory);
    Http::fake([PlayPushSigner::CERTS_URL => Http::response('', 500)]);

    $this->postJson('/api/v1/webhooks/google-play', $this->push, ['Authorization' => 'Bearer '.$this->signer->token()])->assertValidResponse(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
});
