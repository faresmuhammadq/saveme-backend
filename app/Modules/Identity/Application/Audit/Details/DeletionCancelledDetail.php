<?php

namespace App\Modules\Identity\Application\Audit\Details;

final readonly class DeletionCancelledDetail implements AuditDetail
{
    /**
     * @param  'explicit'|'sign_in'  $reason
     */
    public function __construct(private string $reason) {}

    public function toArray(): array
    {
        return ['reason' => $this->reason];
    }
}
