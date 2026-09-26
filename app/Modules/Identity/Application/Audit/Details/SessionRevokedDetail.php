<?php

namespace App\Modules\Identity\Application\Audit\Details;

use App\Modules\Identity\Domain\Sessions\EndReason;

final readonly class SessionRevokedDetail implements AuditDetail
{
    public function __construct(private EndReason $reason) {}

    public function toArray(): array
    {
        return ['reason' => $this->reason->value];
    }
}
