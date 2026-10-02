<?php

namespace App\Modules\Sharing\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A member's role moved between editor and viewer: by the owner (reason
 * `owner`, `actorUserId` set) or by the server when the owner's plan allows
 * fewer members (reason `plan`, no actor). Dispatched after commit,
 * identifiers and scalars only (P-11).
 */
final readonly class MemberRoleChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $walletId,
        public string $memberUserId,
        public string $previousRole,
        public string $role,
        public string $reason,
        public ?string $actorUserId = null,
    ) {}
}
