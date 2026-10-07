<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Domain\Ports\PlayNotificationRepository;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use Illuminate\Support\Facades\Cache;

/**
 * What operators may see of Google Play (spec 024, FR-053, R-12): platform
 * counts for the last 24 hours. Notifications by state, pushes rejected by
 * reason (summed from the hourly counters the webhook keeps, since a refusal
 * writes no row), purchases verified, and purchases unacknowledged for more
 * than 24 hours. Counts only: never a token, an order id or a person.
 */
final class PlayFigures
{
    /** The reason codes a refused push is counted under. */
    public const REJECTION_REASONS = ['malformed', 'algorithm', 'signature', 'issuer', 'audience', 'email', 'email_unverified', 'time', 'expired'];

    public function __construct(
        private readonly PlayNotificationRepository $notifications,
        private readonly PlayPurchaseRepository $purchases,
    ) {}

    /**
     * @return array{received: int, processed: int, ignored: int, failed: int, rejected: int, verified: int, unacknowledged: int}
     */
    public function last24Hours(): array
    {
        $now = now()->toDateTimeImmutable();
        $since = $now->modify('-24 hours');
        $states = $this->notifications->countsSince($since);

        return [
            'received' => array_sum($states),
            'processed' => $states['processed'] ?? 0,
            'ignored' => $states['ignored'] ?? 0,
            'failed' => $states['failed'] ?? 0,
            'rejected' => array_sum($this->rejectedByReason($now)),
            'verified' => $this->purchases->linkedSince($since),
            'unacknowledged' => $this->purchases->unacknowledgedBefore($since),
        ];
    }

    /**
     * Pushes refused in the last 24 hours, by reason.
     *
     * @return array<string, int>
     */
    public function rejectedByReason(\DateTimeImmutable $now): array
    {
        $counts = [];

        foreach (self::REJECTION_REASONS as $reason) {
            $total = 0;

            for ($hour = 0; $hour < 24; $hour++) {
                $total += (int) Cache::get('billing:play:rejected:'.$reason.':'.$now->modify("-{$hour} hours")->setTimezone(new \DateTimeZone('UTC'))->format('YmdH'), 0);
            }

            if ($total > 0) {
                $counts[$reason] = $total;
            }
        }

        return $counts;
    }
}
