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
}
