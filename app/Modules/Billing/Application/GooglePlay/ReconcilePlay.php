<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use Throwable;

/**
 * The hourly pass (spec 024, R-10, FR-044, FR-046): re-reads from Google every
 * granting `google_play` subscription whose period or grace end has passed,
 * every linked purchase still unacknowledged, and every pending purchase
 * younger than 3 days, through the same path as a notification. Safe to run
 * twice and beside notification processing (a lock per purchase).
 */
final class ReconcilePlay
{
    public function __construct(
        private readonly PlayPurchaseRepository $purchases,
        private readonly ReconcilePlayPurchase $reconcile,
    ) {}

    /**
     * @return array{read: int, unavailable: int, failed: int}
     */
    public function run(): array
    {
        $now = now()->toDateTimeImmutable();
        $ids = array_values(array_unique([
            ...$this->purchases->grantingPastEnd($now),
            ...$this->purchases->unacknowledged(),
            ...$this->purchases->pendingSince($now->modify('-3 days')),
        ]));

        $counts = ['read' => 0, 'unavailable' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            try {
                $outcome = $this->reconcile->sync($id);
                $outcome->kind === SyncOutcome::UNAVAILABLE ? $counts['unavailable']++ : $counts['read']++;
            } catch (Throwable) {
                $counts['failed']++;
            }
        }

        $overdue = $this->purchases->unacknowledgedBefore($now->modify('-'.(int) config('billing.google_play.ack_alert_hours').' hours'));

        if ($overdue > 0) {
            PlayLog::ackOverdue($overdue);
        }

        PlayLog::reconciled('hourly', $counts);

        return $counts;
    }
}
