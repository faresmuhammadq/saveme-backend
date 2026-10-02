<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * A `role_changed` entry for a member's role moving between editor and
 * viewer, by the owner or by plan demotion (spec 016): who, from which role
 * to which. Ownership transfers use RoleChangedDetail.
 */
final readonly class MemberRoleDetail implements AuditDetail
{
    public function __construct(
        private string $memberUserId,
        private string $from,
        private string $to,
    ) {}

    public function toArray(): array
    {
        return [
            'member_user_id' => $this->memberUserId,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
