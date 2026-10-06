<?php

namespace App\Modules\Notifications\Domain\Delivery;

/**
 * A Push Notification's life (spec 023, data-model): pending, held while
 * quiet hours last, sending, then one of three terminal states.
 */
enum NotificationState: string
{
    case Pending = 'pending';
    case Held = 'held';
    case Sending = 'sending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Skipped, self::Failed], true);
    }
}
