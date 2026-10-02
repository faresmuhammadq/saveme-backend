<?php

namespace App\Modules\Sharing\Application\Listeners;

use App\Modules\Sharing\Application\EnforceMemberQuota;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Ownership moved to someone whose plan may allow fewer members: enforce the
 * wallet's quota under its new owner (spec 016, R-11). Queued and idempotent;
 * tolerates a wallet deleted meanwhile.
 */
final class DemoteOnOwnershipTransfer implements ShouldQueue
{
    public function __construct(private readonly EnforceMemberQuota $quota) {}

    public function handle(WalletOwnershipTransferred $event): void
    {
        $this->quota->forWallet($event->walletId);
    }
}
