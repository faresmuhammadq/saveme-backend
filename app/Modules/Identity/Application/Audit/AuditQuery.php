<?php

namespace App\Modules\Identity\Application\Audit;

use App\Modules\Identity\Domain\Ports\AuditStore;
use InvalidArgumentException;

/**
 * Reads the audit log in keyset pages on `(occurred_at, id)` (spec 021, R-8,
 * FR-041): newest first, so no append can shift a page. It never writes; the
 * caller that is allowed to read records the read.
 */
final class AuditQuery
{
    public const OLDER = 'older';

    public const NEWER = 'newer';

    public function __construct(private readonly AuditStore $store) {}

    public function page(AuditFilter $filter, ?AuditCursor $cursor, string $direction = self::OLDER, int $limit = 50): AuditPage
    {
        if (! in_array($direction, [self::OLDER, self::NEWER], true) || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('The audit page needs a direction of older or newer and a limit of 1 to 100.');
        }

        if ($cursor === null) {
            $direction = self::OLDER;
        }

        $rows = $this->store->page(
            array_filter([
                'event' => $filter->event,
                'actor_type' => $filter->actorType,
                'actor_id' => $filter->actorId,
                'subject_type' => $filter->subjectType,
                'subject_id' => $filter->subjectId,
                'from' => $filter->fromUtc->format('Y-m-d H:i:s.uP'),
                'until' => $filter->untilUtc->format('Y-m-d H:i:s.uP'),
            ], static fn (?string $member): bool => $member !== null),
            $cursor === null ? null : ['occurred_at' => $cursor->occurredAt, 'id' => $cursor->id],
            $direction === self::NEWER,
            $limit + 1,
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        if ($direction === self::NEWER) {
            $rows = array_reverse($rows);
        }

        $rows = array_map(static fn (array $row): AuditRow => new AuditRow(
            $row['id'],
            $row['occurred_at'],
            $row['event'],
            $row['actor_type'],
            $row['actor_id'],
            $row['subject_type'],
            $row['subject_id'],
            $row['request_id'],
            $row['origin'],
            $row['detail'],
        ), $rows);

        if ($rows === []) {
            return new AuditPage([], null, null);
        }

        $first = $rows[0];
        $last = $rows[count($rows) - 1];
        $before = new AuditCursor($first->occurredAt, $first->id);
        $after = new AuditCursor($last->occurredAt, $last->id);

        $hasOlder = $direction === self::OLDER ? $more : $cursor !== null;
        $hasNewer = $direction === self::NEWER ? $more : $cursor !== null;

        return new AuditPage($rows, $hasOlder ? $after : null, $hasNewer ? $before : null);
    }
}
