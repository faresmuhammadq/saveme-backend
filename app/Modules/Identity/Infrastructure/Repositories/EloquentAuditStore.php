<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\AuditStore;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;

final class EloquentAuditStore implements AuditStore
{
    public function append(array $entry): void
    {
        AuditEntry::query()->forceCreate($entry);
    }
}
