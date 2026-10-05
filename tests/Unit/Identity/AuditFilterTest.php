<?php

use App\Modules\Identity\Application\Audit\AuditFilter;
use App\Modules\Identity\Application\Audit\InvalidAuditFilter;

/*
 * The audit log filter (spec 021, US3; FR-042).
 */

function auditFilterFields(array $input): array
{
    try {
        AuditFilter::from($input, new DateTimeZone('Africa/Cairo'), new DateTimeImmutable('2026-10-05 10:00:00', new DateTimeZone('UTC')));
    } catch (InvalidAuditFilter $invalid) {
        return array_keys($invalid->fields);
    }

    return [];
}

it('defaults to the last seven days in the operator timezone, as UTC bounds', function () {
    $filter = AuditFilter::from([], new DateTimeZone('Africa/Cairo'), new DateTimeImmutable('2026-10-05 22:30:00', new DateTimeZone('UTC')));

    // 22:30 UTC on the 5th is already the 6th in Cairo.
    expect($filter->toDetail())->toBe(['from' => '2026-09-30', 'to' => '2026-10-06'])
        ->and($filter->fromUtc->format('Y-m-d H:i:s'))->toBe('2026-09-29 21:00:00')
        ->and($filter->untilUtc->format('Y-m-d H:i:s'))->toBe('2026-10-06 21:00:00');
});

it('keeps only the members that were given in the detail', function () {
    $id = '0190aaaa-bbbb-7ccc-8ddd-eeeeffff0000';
    $filter = AuditFilter::from(
        ['event' => 'admin_action', 'subject_type' => 'user', 'subject_id' => $id, 'from' => '2026-10-01', 'to' => '2026-10-02'],
        new DateTimeZone('UTC'),
        new DateTimeImmutable('2026-10-05'),
    );

    expect($filter->toDetail())->toBe(['event' => 'admin_action', 'subject_type' => 'user', 'subject_id' => $id, 'from' => '2026-10-01', 'to' => '2026-10-02']);
});

it('names the field of every invalid member', function (array $input, string $field) {
    expect(auditFilterFields($input))->toBe([$field]);
})->with([
    'range over 400 days' => [['from' => '2025-01-01', 'to' => '2026-10-01'], 'from'],
    'end before start' => [['from' => '2026-10-04', 'to' => '2026-10-01'], 'to'],
    'malformed start' => [['from' => '04/10/2026'], 'from'],
    'impossible end' => [['to' => '2026-02-31'], 'to'],
    'unknown event' => [['event' => 'nope'], 'event'],
    'unknown actor type' => [['actor_type' => 'robot'], 'actor_type'],
    'unknown subject type' => [['subject_type' => 'planet'], 'subject_type'],
    'short actor id' => [['actor_id' => '1234'], 'actor_id'],
    'short subject id' => [['subject_id' => 'abc'], 'subject_id'],
]);

it('accepts exactly 400 days', function () {
    expect(auditFilterFields(['from' => '2025-08-30', 'to' => '2026-10-05']))->toBe(['from']);
    expect(auditFilterFields(['from' => '2025-08-31', 'to' => '2026-10-05']))->toBe([]);
});
