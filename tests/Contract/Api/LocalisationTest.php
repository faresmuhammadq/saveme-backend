<?php

/*
 * Accept-Language selects the language of human-readable members, and
 * Content-Language reports the one used (docs/api-conventions.md).
 */

beforeEach(fn () => registerApiProbes());

test('Arabic is served when asked for; title and code are never localised', function () {
    $response = $this->withHeaders(jsonHeaders(['Accept-Language' => 'ar']))->get('/api/v1/nope');

    $response->assertHeader('Content-Language', 'ar')
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('title', 'Not found')
        ->assertJsonPath('detail', trans('problems.NOT_FOUND', [], 'ar'));
});

test('an unsupported language falls back to English', function () {
    $this->withHeaders(jsonHeaders(['Accept-Language' => 'fr']))->get('/api/v1/_probe')
        ->assertOk()
        ->assertHeader('Content-Language', 'en');
});
