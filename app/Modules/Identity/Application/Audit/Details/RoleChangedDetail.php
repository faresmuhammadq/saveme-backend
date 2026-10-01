<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * A `role_changed` entry (written by Wallets, spec 010): an ownership
 * transfer names the previous and the new owner by identifier. Never an
 * amount or a name (security baseline, What an entry must never contain).
 */
final readonly class RoleChangedDetail implements AuditDetail
{
    public function __construct(
        private string $previousOwnerId,
        private string $newOwnerId,
    ) {}

    public function toArray(): array
    {
        return [
            'previous_owner_id' => $this->previousOwnerId,
            'new_owner_id' => $this->newOwnerId,
        ];
    }
}
