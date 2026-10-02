<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * A `membership_granted` or `membership_revoked` entry (written by Wallets,
 * spec 016): the member by identifier and why (`invitation`, `removed`,
 * `left`). Never an address or a name.
 */
final readonly class MembershipDetail implements AuditDetail
{
    public function __construct(
        private string $memberUserId,
        private string $reason,
    ) {}

    public function toArray(): array
    {
        return [
            'member_user_id' => $this->memberUserId,
            'reason' => $this->reason,
        ];
    }
}
