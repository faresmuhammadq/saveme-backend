<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Sharing\Application\Departure\RevokeSentInvitations;
use App\Modules\Sharing\Application\Export\InvitationSection;
use App\Modules\Sharing\Application\Listeners\DemoteOnLapse;
use App\Modules\Sharing\Application\Listeners\DemoteOnOwnershipTransfer;
use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Sharing's wallet deletion participant with Wallets (tagged with
 * the interface's name) and its queued listeners for a lapsed subscription
 * and an ownership transfer (spec 016, R-11).
 */
class SharingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([InvitationsGoWithWallet::class], WalletDeletionParticipant::class);

        // Identity orchestrates exports and purges; Sharing supplies its share (spec 022, P-02).
        $this->app->tag([InvitationSection::class], 'identity.export-sections');
        $this->app->tag([RevokeSentInvitations::class], 'identity.purge-participants');
    }

    public function boot(): void
    {
        Event::listen(SubscriptionLapsed::class, DemoteOnLapse::class);
        Event::listen(WalletOwnershipTransferred::class, DemoteOnOwnershipTransfer::class);
    }
}
