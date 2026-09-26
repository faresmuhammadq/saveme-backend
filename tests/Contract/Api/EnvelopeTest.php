<?php

/*
 * The single success shape (docs/api-conventions.md, Success envelope).
 */

beforeEach(fn () => registerApiProbes());

test('a success body is data plus an object meta, never an array', function () {
    $response = $this->withHeaders(jsonHeaders())->get('/api/v1/_probe');

    $response->assertOk()->assertExactJson(['data' => ['ok' => true], 'meta' => []]);
    expect($response->getContent())->toContain('"meta":{}');
});
