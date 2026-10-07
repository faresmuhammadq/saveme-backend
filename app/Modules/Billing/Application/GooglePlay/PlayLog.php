<?php

namespace App\Modules\Billing\Application\GooglePlay;

use Illuminate\Support\Facades\Log;

/**
 * The fixed log events of the Google Play paths (spec 024, FR-062, R-13):
 * identifiers, counts and codes only. Never a token, a reference, an order
 * id, a URL or an exception message.
 */
final class PlayLog
{
    public static function verified(string $purchaseId, string $outcome): void
    {
        Log::info('billing_play_verified', ['purchase_id' => $purchaseId, 'outcome' => $outcome]);
    }

    public static function pushRejected(string $reason): void
    {
        Log::warning('billing_play_push_rejected', ['reason' => $reason]);
    }

    public static function notificationFailed(string $notificationId): void
    {
        Log::error('billing_play_notification_failed', ['notification_id' => $notificationId]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    public static function reconciled(string $pass, array $counts): void
    {
        Log::info('billing_play_reconciled', ['run' => $pass, ...$counts]);
    }

    public static function ackOverdue(int $count): void
    {
        Log::warning('billing_play_ack_overdue', ['count' => $count]);
    }

    public static function productUnmapped(string $purchaseId): void
    {
        Log::warning('billing_play_product_unmapped', ['purchase_id' => $purchaseId]);
    }

    public static function unreachable(string $purchaseId): void
    {
        Log::warning('billing_play_unreachable', ['purchase_id' => $purchaseId]);
    }

    public static function stateIgnored(string $purchaseId): void
    {
        Log::info('billing_play_state_ignored', ['purchase_id' => $purchaseId]);
    }
}
