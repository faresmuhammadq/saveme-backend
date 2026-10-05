<?php

namespace App\Modules\Identity\Application\Audit;

/**
 * One audit entry as a screen may read it (spec 021, FR-043).
 */
final readonly class AuditRow
{
    /**
     * @param  array<string, scalar|null>  $detail
     */
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $event,
        public string $actorType,
        public ?string $actorId,
        public string $subjectType,
        public ?string $subjectId,
        public ?string $requestId,
        public ?string $origin,
        public array $detail,
    ) {}
}
