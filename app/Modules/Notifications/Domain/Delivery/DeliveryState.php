<?php

namespace App\Modules\Notifications\Domain\Delivery;

/**
 * One Push Delivery: one notification to one registration.
 */
enum DeliveryState: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Pruned = 'pruned';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
