<?php

namespace App\Modules\Wallets\Application\Dto;

use App\Modules\Wallets\Domain\Membership\MemberRecord;

/**
 * A member with the display name the other members may see.
 */
final readonly class MemberView
{
    public function __construct(
        public MemberRecord $member,
        public ?string $displayName,
    ) {}
}
