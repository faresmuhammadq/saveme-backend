<?php

/*
 * JSON only, in both directions (docs/api-conventions.md, Media types).
 */

beforeEach(fn () => registerApiProbes());

test('an Accept header excluding JSON is 406, still as a problem body', function () {
    $this->withHeaders([...clientHeaders(), 'Accept' => 'text/html'])->get('/api/v1/_probe')
        ->assertStatus(406)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_ACCEPTABLE');
});

test('a body that is not JSON is 415', function () {
    $this->call('POST', '/api/v1/_probe', [], [], [], $this->transformHeadersToServerVars([...jsonHeaders(), 'Content-Type' => 'text/plain']), 'name=x')
        ->assertStatus(415)
        ->assertJsonPath('code', 'UNSUPPORTED_MEDIA_TYPE');
});

test('an unparseable body is MALFORMED_JSON', function () {
    $this->call('POST', '/api/v1/_probe', [], [], [], $this->transformHeadersToServerVars([...jsonHeaders(), 'Content-Type' => 'application/json']), '{bad')
        ->assertStatus(400)
        ->assertJsonPath('code', 'MALFORMED_JSON');
});

test('a JSON array where an object is required is MALFORMED_JSON', function () {
    $this->call('POST', '/api/v1/_probe', [], [], [], $this->transformHeadersToServerVars([...jsonHeaders(), 'Content-Type' => 'application/json']), '[1,2]')
        ->assertStatus(400)
        ->assertJsonPath('code', 'MALFORMED_JSON');
});
