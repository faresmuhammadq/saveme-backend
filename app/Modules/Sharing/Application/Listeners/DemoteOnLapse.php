<?php

namespace App\Modules\Sharing\Application\Listeners;

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Sharing\Application\EnforceMemberQuota;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A subscription stopped granting fully: enforce the member quota of every
 * wallet its holder owns (spec 016, R-11). Queued and idempotent; a lapse
 * into grace changes nothing because grace still grants.
 */
final class DemoteOnLapse implements ShouldQueue
{
    public function __construct(private readonly EnforceMemberQuota $quota) {}

    public function handle(SubscriptionLapsed $event): void
    {
        $this->quota->forOwner($event->userId);
    }
}
