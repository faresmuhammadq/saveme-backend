<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Ownership of a wallet passed to a member, by the owner or by an account
 * purge: the new owner hears of it (spec 023, FR-027).
 */
final class NotifyOwnershipReceived implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(WalletOwnershipTransferred $event): void
    {
        $this->notifications->create(new NotificationDraft(
            $event->newOwnerId,
            MessageType::WalletOwnershipReceived,
            'event:'.$event->eventId,
            ['wallet_id' => $event->walletId],
        ));
    }
}
