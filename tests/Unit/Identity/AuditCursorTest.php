<?php

use App\Modules\Identity\Application\Audit\AuditCursor;

/*
 * The opaque audit page cursor (spec 021, US3; FR-041).
 */

it('round-trips an instant and an id', function () {
    $cursor = new AuditCursor('2026-10-05T10:00:00.123456+00:00', '0190aaaa-bbbb-7ccc-8ddd-eeeeffff0000');
    $decoded = AuditCursor::decode($cursor->encode());

    expect($decoded)->not->toBeNull()
        ->and($decoded->occurredAt)->toBe('2026-10-05T10:00:00.123456+00:00')
        ->and($decoded->id)->toBe('0190aaaa-bbbb-7ccc-8ddd-eeeeffff0000');
});

it('reads a tampered or garbage token as no cursor', function (string $token) {
    expect(AuditCursor::decode($token))->toBeNull();
})->with([
    'empty' => [''],
    'not base64' => ['!!!'],
    'not json' => [base64_encode('hello')],
    'wrong shape' => [base64_encode('{"a":1}')],
    'bad id' => [base64_encode('{"t":"2026-10-05T10:00:00.000000+00:00","i":"x"}')],
    'bad instant' => [base64_encode('{"t":"yesterday","i":"0190aaaa-bbbb-7ccc-8ddd-eeeeffff0000"}')],
]);
