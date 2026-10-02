<?php

namespace App\Modules\Wallets\Application\Activity;

use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\ActivityLog;

/**
 * How the Wallets services and other modules (Sharing) record an act in a
 * wallet's activity (ADR-0020). Called inside the unit of work of the write
 * it records, only for an applied write. Identifiers only: there is no
 * parameter an amount, note, name or address could travel in.
 */
final class WalletActivity
{
    public function __construct(private readonly ActivityLog $log) {}

    public function record(string $walletId, ?string $actorId, ActivityAction $action, ActivitySubject $subject, string $subjectId, ?Role $role = null): void
    {
        $this->log->record($walletId, $actorId, $action, $subject, $subjectId, $role);
    }
}
