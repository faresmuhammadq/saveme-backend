<?php

namespace App\Modules\Identity\Application\Audit;

use App\Modules\Identity\Application\Audit\Details\AuditDetail;
use App\Modules\Identity\Application\Audit\Details\NoDetail;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;

/**
 * One act to record: what happened, who did it, and to what — by identifier.
 */
final readonly class AuditRecord
{
    public function __construct(
        public AuditEvent $event,
        public ActorType $actorType,
        public ?string $actorId,
        public SubjectType $subjectType,
        public ?string $subjectId,
        public AuditDetail $detail = new NoDetail,
    ) {}

    public static function byUser(AuditEvent $event, string $userId, SubjectType $subjectType, ?string $subjectId, AuditDetail $detail = new NoDetail): self
    {
        return new self($event, ActorType::User, $userId, $subjectType, $subjectId, $detail);
    }
}
