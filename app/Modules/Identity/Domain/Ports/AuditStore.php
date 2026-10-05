<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * The single append-only audit log (security baseline, Who owns the audit log).
 */
interface AuditStore
{
    /**
     * @param  array{id: string, occurred_at: string, event: string, actor_type: string, actor_id: ?string, subject_type: string, subject_id: ?string, request_id: ?string, origin: ?string, detail: array<string, scalar|null>}  $entry
     */
    public function append(array $entry): void;

    /**
     * Entries inside the criteria, ordered by `(occurred_at, id)` descending, or
     * ascending when `$ascending`, starting strictly after the position given
     * in that order. Read-only.
     *
     * @param  array{event?: string, actor_type?: string, actor_id?: string, subject_type?: string, subject_id?: string, from: string, until: string}  $criteria  `from` inclusive and `until` exclusive, UTC
     * @param  array{occurred_at: string, id: string}|null  $after
     * @return list<array{id: string, occurred_at: string, event: string, actor_type: string, actor_id: ?string, subject_type: string, subject_id: ?string, request_id: ?string, origin: ?string, detail: array<string, scalar|null>}>
     */
    public function page(array $criteria, ?array $after, bool $ascending, int $limit): array;
}
