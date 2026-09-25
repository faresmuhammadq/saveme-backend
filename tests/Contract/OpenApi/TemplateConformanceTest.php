<?php

use Illuminate\Support\Facades\Route;
use Spectator\Spectator;

/*
 * The path template (docs/openapi/paths/_template.yaml) is what every feature
 * copies. These tests prove it stays valid OpenAPI and that the shared
 * components it references accept conforming responses and reject others.
 */
beforeEach(function () {
    config(['spectator.sources.local.base_path' => __DIR__.'/Fixtures']);
    Spectator::using('template-index.yaml');
});

it('validates a conforming collection response', function () {
    Route::middleware('api')->get('api/v1/examples', fn () => response()->json([
        'data' => [],
        'meta' => ['next_cursor' => null, 'has_more' => false, 'limit' => 50],
    ]));

    $this->withHeaders(clientHeaders())
        ->getJson('/api/v1/examples')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

it('rejects a non-conforming response', function () {
    Route::middleware('api')->get('api/v1/examples', fn () => response()->json([
        'data' => [],
        'meta' => ['next_cursor' => 7, 'has_more' => 'no'],
    ]));

    $this->withHeaders(clientHeaders())
        ->getJson('/api/v1/examples')
        ->assertValidResponse(200);
})->throws(ErrorException::class);

it('accepts a 3.1 nullable instant and a shared problem response', function () {
    $id = '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70';
    Route::middleware('api')->put('api/v1/examples/{id}', fn () => response()->json([
        'data' => [
            'id' => $id, 'version' => 1, 'change_seq' => 10,
            'created_at' => '2026-09-15T08:30:00.123456Z', 'updated_at' => '2026-09-15T08:30:00.123456Z',
            'deleted_at' => null, 'wallet_id' => '0192f79e-0000-7000-8000-000000000001',
            'amount_minor' => -12500, 'currency' => 'EGP', 'date' => '2026-09-15',
        ],
        'meta' => (object) [],
    ], 201));

    $this->withHeaders(clientHeaders())
        ->putJson("/api/v1/examples/{$id}", ['amount_minor' => -12500, 'currency' => 'EGP', 'date' => '2026-09-15', 'version' => 0])
        ->assertValidRequest()
        ->assertValidResponse(201);
});

it('validates a problem response from the shared responses file', function () {
    $id = '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70';
    Route::middleware('api')->put('api/v1/examples/{id}', fn () => response()->json([
        'type' => 'https://api.example/problems/not-found', 'title' => 'Not found', 'status' => 404,
        'detail' => 'Not found.', 'code' => 'NOT_FOUND', 'request_id' => '0192f7a0-9999-7000-8000-00000000abcd',
    ], 404, ['Content-Type' => 'application/problem+json']));

    $this->withHeaders(clientHeaders())
        ->putJson("/api/v1/examples/{$id}", ['amount_minor' => 1, 'currency' => 'EGP', 'date' => '2026-09-15'])
        ->assertValidResponse(404);
});
