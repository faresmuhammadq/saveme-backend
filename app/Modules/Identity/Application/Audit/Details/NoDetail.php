<?php

namespace App\Modules\Identity\Application\Audit\Details;

final readonly class NoDetail implements AuditDetail
{
    public function toArray(): array
    {
        return [];
    }
}
