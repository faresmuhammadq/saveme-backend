<?php

namespace App\Modules\Identity\Application\Audit;

/**
 * One page of the audit log, newest first, with the cursors to its neighbours.
 */
final readonly class AuditPage
{
    /**
     * @param  list<AuditRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?AuditCursor $olderCursor,
        public ?AuditCursor $newerCursor,
    ) {}
}
