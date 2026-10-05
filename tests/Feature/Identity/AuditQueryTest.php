<?php

use App\Modules\Identity\Application\Audit\AuditFilter;
use App\Modules\Identity\Application\Audit\AuditPage;
use App\Modules\Identity\Application\Audit\AuditQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The audit log read: keyset pages that never skip or repeat (spec 021, US3; FR-041, FR-042).
 */

beforeEach(function () {
    $this->freezeTime();
});

function seedAuditEntry(array $attributes = []): string
{
    $id = $attributes['id'] ?? (string) Str::uuid7();
    DB::table('audit_entries')->insert(array_merge([
        'id' => $id,
        'occurred_at' => now()->subHour(),
        'event' => 'session_issued',
        'actor_type' => 'user',
        'actor_id' => (string) Str::uuid7(),
        'subject_type' => 'session',
        'subject_id' => (string) Str::uuid7(),
        'request_id' => null,
        'origin' => '203.0.113.0/24',
        'detail' => '{}',
    ], $attributes, ['id' => $id]));

    return $id;
}

function auditFilter(array $input = []): AuditFilter
{
    return AuditFilter::from($input, new DateTimeZone('UTC'), now()->toDateTimeImmutable());
}

function auditIds(AuditPage $page): array
{
    return array_map(fn ($row): string => $row->id, $page->rows);
}

/** @return list<string> ids newest first */
function seedAuditRun(int $count): array
{
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = seedAuditEntry(['occurred_at' => now()->subMinutes($i + 1)]);
    }

    return $ids;
}

it('lists the newest entries first, 50 to a page, and links to the older page', function () {
    $ids = seedAuditRun(120);

    $page = app(AuditQuery::class)->page(auditFilter(), null);

    expect(auditIds($page))->toBe(array_slice($ids, 0, 50))
        ->and($page->olderCursor)->not->toBeNull()
        ->and($page->newerCursor)->toBeNull();
});

it('walks older and newer without skipping or repeating a row', function () {
    $ids = seedAuditRun(120);
    $query = app(AuditQuery::class);

    $first = $query->page(auditFilter(), null);
    $second = $query->page(auditFilter(), $first->olderCursor, 'older');
    $third = $query->page(auditFilter(), $second->olderCursor, 'older');

    expect(auditIds($second))->toBe(array_slice($ids, 50, 50))
        ->and(auditIds($third))->toBe(array_slice($ids, 100, 20))
        ->and($third->olderCursor)->toBeNull()
        ->and($third->newerCursor)->not->toBeNull();

    $back = $query->page(auditFilter(), $third->newerCursor, 'newer');
    $backAgain = $query->page(auditFilter(), $back->newerCursor, 'newer');

    expect(auditIds($back))->toBe(array_slice($ids, 50, 50))
        ->and(auditIds($backAgain))->toBe(array_slice($ids, 0, 50))
        ->and($backAgain->newerCursor)->toBeNull()
        ->and($backAgain->olderCursor)->not->toBeNull();
});

it('keeps the same rows when entries are appended between calls', function () {
    $ids = seedAuditRun(120);
    $query = app(AuditQuery::class);

    $first = $query->page(auditFilter(), null);
    $second = $query->page(auditFilter(), $first->olderCursor, 'older');

    $appended = seedAuditEntry(['occurred_at' => now()]);
    seedAuditEntry(['occurred_at' => now()->subMinutes(500)]);

    $back = $query->page(auditFilter(), $second->newerCursor, 'newer');
    expect(auditIds($back))->toBe(array_slice($ids, 0, 50));

    $top = $query->page(auditFilter(), $back->newerCursor, 'newer');
    expect(auditIds($top))->toBe([$appended]);
});

it('breaks ties on equal occurred_at by id with no skip or repeat', function () {
    $instant = now()->subMinutes(5);
    $ids = [];

    for ($i = 0; $i < 7; $i++) {
        $ids[] = seedAuditEntry(['occurred_at' => $instant]);
    }

    usort($ids, fn (string $a, string $b): int => strcmp($b, $a));
    $query = app(AuditQuery::class);

    $one = $query->page(auditFilter(), null, 'older', 3);
    $two = $query->page(auditFilter(), $one->olderCursor, 'older', 3);
    $three = $query->page(auditFilter(), $two->olderCursor, 'older', 3);

    expect(auditIds($one))->toBe(array_slice($ids, 0, 3))
        ->and(auditIds($two))->toBe(array_slice($ids, 3, 3))
        ->and(auditIds($three))->toBe(array_slice($ids, 6, 1))
        ->and(auditIds($query->page(auditFilter(), $three->newerCursor, 'newer', 3)))->toBe(array_slice($ids, 3, 3));
});

it('narrows by each filter alone and combined', function () {
    $actor = (string) Str::uuid7();
    $subject = (string) Str::uuid7();
    $match = seedAuditEntry(['event' => 'admin_action', 'actor_type' => 'operator', 'actor_id' => $actor, 'subject_type' => 'user', 'subject_id' => $subject]);
    $other = seedAuditEntry(['event' => 'sign_in_failed', 'actor_type' => 'system', 'actor_id' => null, 'subject_type' => 'plan']);
    $query = app(AuditQuery::class);
    $ids = fn (array $input): array => auditIds($query->page(auditFilter($input), null));

    expect($ids(['event' => 'admin_action']))->toBe([$match])
        ->and($ids(['actor_type' => 'system']))->toBe([$other])
        ->and($ids(['actor_id' => $actor]))->toBe([$match])
        ->and($ids(['subject_type' => 'plan']))->toBe([$other])
        ->and($ids(['subject_id' => $subject]))->toBe([$match])
        ->and($ids(['event' => 'admin_action', 'subject_type' => 'user', 'actor_id' => $actor]))->toBe([$match])
        ->and($ids(['event' => 'admin_action', 'subject_type' => 'plan']))->toBe([]);
});

it('keeps to the date range in the operator timezone', function () {
    $inside = seedAuditEntry(['occurred_at' => '2026-10-02 21:00:00+00']);
    $last = seedAuditEntry(['occurred_at' => '2026-10-03 20:59:59+00']);
    seedAuditEntry(['occurred_at' => '2026-10-02 20:59:59+00']);
    seedAuditEntry(['occurred_at' => '2026-10-03 21:00:00+00']);

    $filter = AuditFilter::from(['from' => '2026-10-03', 'to' => '2026-10-03'], new DateTimeZone('Africa/Cairo'), now()->toDateTimeImmutable());
    $page = app(AuditQuery::class)->page($filter, null);

    expect(auditIds($page))->toEqualCanonicalizing([$inside, $last]);
});

it('returns the fields a screen needs and never writes', function () {
    $requestId = (string) Str::uuid7();
    $id = seedAuditEntry(['request_id' => $requestId, 'detail' => json_encode(['via' => 'password'])]);
    $before = DB::table('audit_entries')->count();

    $row = app(AuditQuery::class)->page(auditFilter(), null)->rows[0];

    expect($row->id)->toBe($id)
        ->and($row->requestId)->toBe($requestId)
        ->and($row->origin)->toBe('203.0.113.0/24')
        ->and($row->detail)->toBe(['via' => 'password'])
        ->and($row->occurredAt)->toEndWith('+00:00')
        ->and(DB::table('audit_entries')->count())->toBe($before);
});

it('refuses a page size outside 1 to 100', function (int $limit) {
    app(AuditQuery::class)->page(auditFilter(), null, 'older', $limit);
})->with([0, 101])->throws(InvalidArgumentException::class);
