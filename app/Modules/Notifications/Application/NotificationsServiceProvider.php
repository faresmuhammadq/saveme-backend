<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\Events\SubscriptionRenewalDue;
use App\Modules\Budgeting\Domain\Events\BudgetExceeded;
use App\Modules\Identity\Domain\Events\DeletionReminderDue;
use App\Modules\Identity\Domain\Events\DeviceSessionEnded;
use App\Modules\Identity\Domain\Events\ExportReady;
use App\Modules\Notifications\Application\Departure\NotificationsPurge;
use App\Modules\Notifications\Application\Listeners\DropRegistrationOfEndedSession;
use App\Modules\Notifications\Application\Listeners\NotifyBudgetExceeded;
use App\Modules\Notifications\Application\Listeners\NotifyDeletionReminder;
use App\Modules\Notifications\Application\Listeners\NotifyExportReady;
use App\Modules\Notifications\Application\Listeners\NotifyInvitationReceived;
use App\Modules\Notifications\Application\Listeners\NotifyMemberRemoved;
use App\Modules\Notifications\Application\Listeners\NotifyMemberRoleChanged;
use App\Modules\Notifications\Application\Listeners\NotifyOwnershipReceived;
use App\Modules\Notifications\Application\Listeners\NotifySubscriptionLapsed;
use App\Modules\Notifications\Application\Listeners\NotifySubscriptionRenewalDue;
use App\Modules\Notifications\Domain\Delivery\DeliveryPolicy;
use App\Modules\Sharing\Domain\Events\InvitationSent;
use App\Modules\Sharing\Domain\Events\MemberRevoked;
use App\Modules\Sharing\Domain\Events\MemberRoleChanged;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Notifications' application services and subscribes its queued,
 * idempotent listeners to the events of the other modules (spec 023, P-11).
 * The gateway binding and its boot refusals live with the adapters in
 * NotificationsPersistenceServiceProvider.
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeliveryPolicy::class, fn (): DeliveryPolicy => new DeliveryPolicy(
            (int) config('notifications.rate.per_hour'),
            (int) config('notifications.rate.per_day'),
        ));

        // Identity orchestrates purges; Notifications supplies its step (spec 023, FR-044, P-02).
        $this->app->tag([NotificationsPurge::class], 'identity.purge-participants');
    }

    public function boot(): void
    {
        Event::listen(DeviceSessionEnded::class, DropRegistrationOfEndedSession::class);
        Event::listen(InvitationSent::class, NotifyInvitationReceived::class);
        Event::listen(MemberRoleChanged::class, NotifyMemberRoleChanged::class);
        Event::listen(MemberRevoked::class, NotifyMemberRemoved::class);
        Event::listen(WalletOwnershipTransferred::class, NotifyOwnershipReceived::class);
        Event::listen(BudgetExceeded::class, NotifyBudgetExceeded::class);
        Event::listen(SubscriptionRenewalDue::class, NotifySubscriptionRenewalDue::class);
        Event::listen(SubscriptionLapsed::class, NotifySubscriptionLapsed::class);
        Event::listen(ExportReady::class, NotifyExportReady::class);
        Event::listen(DeletionReminderDue::class, NotifyDeletionReminder::class);
    }
}
