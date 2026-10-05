<?php

namespace App\Modules\Identity\Application\Audit\Details;

use App\Modules\Identity\Domain\Deletion\PurgeCounts;

/**
 * A `deletion_completed` entry: counts only (spec 022, FR-055).
 */
final readonly class DeletionCompletedDetail implements AuditDetail
{
    public function __construct(private PurgeCounts $counts) {}

    public function toArray(): array
    {
        return [
            'wallets_transferred' => $this->counts->get('wallets_transferred'),
            'wallets_deleted' => $this->counts->get('wallets_deleted'),
            'memberships_ended' => $this->counts->get('memberships_ended'),
        ];
    }
}
