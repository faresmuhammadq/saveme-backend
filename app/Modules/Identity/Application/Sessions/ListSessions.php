<?php

namespace App\Modules\Identity\Application\Sessions;

use App\Modules\Identity\Application\Dto\DeviceSessionView;
use App\Modules\Identity\Domain\Ports\SessionRepository;
use App\Modules\Identity\Domain\Sessions\SessionRecord;
use App\Modules\Identity\Domain\Sessions\SessionState;

/**
 * The caller's own sessions, one keyset page at a time (docs/api-conventions.md,
 * Collections). The cursor is opaque to clients; here it is the sort value and
 * id of the last row returned.
 */
final class ListSessions
{
    public function __construct(private readonly SessionRepository $sessions) {}

    /**
     * @param  'signed_in_at'|'last_used_at'  $sort
     * @param  array{value: string|null, id: string}|null  $after
     * @return array{items: list<DeviceSessionView>, next: array{value: string|null, id: string}|null}
     */
    public function page(string $callerId, string $currentSessionId, ?SessionState $state, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $rows = $this->sessions->page($callerId, $state, $sort, $descending, $after, $limit + 1);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = array_map(fn (SessionRecord $session): DeviceSessionView => new DeviceSessionView($session, $session->id === $currentSessionId), $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'items' => $items,
            'next' => $hasMore && $last !== null ? ['value' => self::sortValue($last, $sort), 'id' => $last->id] : null,
        ];
    }

    private static function sortValue(SessionRecord $session, string $sort): string
    {
        $value = $sort === 'last_used_at' ? ($session->lastUsedAt ?? $session->signedInAt) : $session->signedInAt;

        return $value->format('Y-m-d\TH:i:s.uP');
    }
}
