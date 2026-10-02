<?php

namespace App\Modules\Wallets\Application\Membership\Listeners;

use App\Modules\Identity\Domain\Events\ProfileUpdated;
use App\Modules\Wallets\Application\Membership\RestampMemberNames;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A person changed their display name: queue the re-send of their
 * memberships on every wallet feed (ADR-0021). Any other profile change
 * does nothing here.
 */
final class ResendMemberNames implements ShouldQueue
{
    public function handle(ProfileUpdated $event): void
    {
        if (! in_array('display_name', $event->changedFields, true)) {
            return;
        }

        RestampMemberNames::dispatch($event->userId);
    }
}
