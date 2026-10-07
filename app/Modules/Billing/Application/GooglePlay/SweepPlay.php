<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Ports\PlayNotificationRepository;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\TokenLookup;
use App\Modules\Identity\Application\Accounts;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The daily pass (spec 024, R-10, FR-045): re-reads every granting `google_play`
 * subscription not read for 7 days, lists Google's voided purchases for the
 * last 30 days and revokes the linked ones, and deletes what retention says
 * (unlinked purchases after 10 days, inbox rows after 30). The voided-purchases
 * watermark is kept in the cache; losing it re-reads 30 days, harmlessly.
 */
final class SweepPlay
{
    private const WATERMARK = 'billing:play:voided-watermark';

    public function __construct(
        private readonly PlayPurchaseRepository $purchases,
        private readonly PlayNotificationRepository $notifications,
        private readonly ReconcilePlayPurchase $reconcile,
        private readonly PlayBillingGateway $gateway,
        private readonly ApplyPlayState $apply,
        private readonly TokenLookup $lookup,
        private readonly Accounts $accounts,
    ) {}

    /**
     * @return array{stale: int, revoked: int, purchases_deleted: int, notifications_deleted: int}
     */
    public function run(): array
    {
        $now = now()->toDateTimeImmutable();
        $counts = ['stale' => 0, 'revoked' => 0, 'purchases_deleted' => 0, 'notifications_deleted' => 0];

        foreach ($this->purchases->grantingSyncedBefore($now->modify('-7 days')) as $id) {
            try {
                $this->reconcile->sync($id);
                $counts['stale']++;
            } catch (Throwable) {
                // The next pass tries again.
            }
        }

        $counts['revoked'] = $this->sweepVoided($now);
        $counts['purchases_deleted'] = $this->purchases->deleteUnlinkedBefore($now->modify('-'.(int) config('billing.google_play.unlinked_keep_days').' days'));
        $counts['notifications_deleted'] = $this->notifications->deleteReceivedBefore($now->modify('-'.(int) config('billing.google_play.inbox_keep_days').' days'));

        PlayLog::reconciled('daily', $counts);

        return $counts;
    }

    private function sweepVoided(DateTimeImmutable $now): int
    {
        $floor = $now->modify('-30 days');
        $watermark = Cache::get(self::WATERMARK);
        $since = is_int($watermark) && $watermark > $floor->getTimestamp() ? (new DateTimeImmutable('@'.$watermark)) : $floor;
        $revoked = 0;
        $page = null;

        do {
            $voided = $this->gateway->voidedSince($since, $page);

            if (! $voided->available) {
                return $revoked;
            }

            foreach ($voided->items as $item) {
                $purchase = $this->purchases->findByLookup($this->lookup->for($item->purchaseToken));

                if ($purchase === null || $purchase->userId === null || $this->accounts->departedAmong([$purchase->userId]) !== []) {
                    continue;
                }

                DB::transaction(function () use ($purchase, $item, $now, &$revoked): void {
                    $locked = $this->purchases->lock($purchase->id);

                    if ($locked === null || $locked->subscriptionId === null || $locked->playState === PlayState::Revoked) {
                        return;
                    }

                    $this->apply->revoke($locked, (string) $locked->userId, $item->reason, $now);
                    $this->purchases->update($locked->id, ['play_state' => 'revoked']);
                    $revoked++;
                });
            }

            $page = $voided->nextPageToken;
        } while ($page !== null);

        Cache::forever(self::WATERMARK, $now->getTimestamp());

        return $revoked;
    }
}
