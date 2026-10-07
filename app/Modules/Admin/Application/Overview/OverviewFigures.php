<?php

namespace App\Modules\Admin\Application\Overview;

use App\Modules\Admin\Application\Reenrolment\ReenrolmentQuery;
use App\Modules\Admin\Domain\AnonymityFloor;
use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Application\Figures\BillingFigures;
use App\Modules\Billing\Application\GooglePlay\PlayFigures;
use App\Modules\Identity\Application\Figures\AccountFigures;
use App\Modules\Notifications\Application\NotificationFigures;
use App\Modules\Wallets\Application\Membership\MembershipCounts;

/**
 * The overview's figures (spec 021, FR-010 to FR-013, R-11): population
 * counts pass through the anonymity floor, the needs-attention figures are
 * exact. Push counts for the last 24 hours are platform totals, never per
 * person (spec 023, FR-047). Google Play counts for the last 24 hours are
 * platform totals too (spec 024, FR-053). Computed on each call; nothing is stored and
 * nothing is audited.
 */
final class OverviewFigures
{
    public function __construct(
        private readonly AccountFigures $accounts,
        private readonly BillingFigures $billing,
        private readonly MembershipCounts $memberships,
        private readonly ClaimQuery $claims,
        private readonly ReenrolmentQuery $reenrolments,
        private readonly NotificationFigures $notifications,
        private readonly PlayFigures $play,
    ) {}

    /**
     * @return array{
     *     figures: array<string, int|string>,
     *     claims_awaiting: int,
     *     oldest_claim_at: ?string,
     *     reenrolments_open: int,
     *     push: array{created: int, sent: int, skipped: int, failed: int, pruned: int},
     *     play: array{received: int, processed: int, ignored: int, failed: int, rejected: int, verified: int, unacknowledged: int},
     *     play_rejections: array<string, int>,
     * }
     */
    public function compute(): array
    {
        $now = now()->toDateTimeImmutable();
        $accounts = $this->accounts->compute($now);
        $billing = $this->billing->compute($now);
        $awaiting = $this->claims->awaitingSummary();

        return [
            'figures' => array_map(AnonymityFloor::display(...), [
                'accounts' => $accounts->total,
                'verified' => $accounts->verified,
                'pending_deletion' => $accounts->pendingDeletion,
                'new_7_days' => $accounts->newInSevenDays,
                'new_30_days' => $accounts->newInThirtyDays,
                'paid_google_play' => $billing->paidGooglePlay,
                'test_google_play' => $billing->testGooglePlay,
                'paid_instapay' => $billing->paidInstapay,
                'granted_manual' => $billing->grantedManual,
                'in_grace' => $billing->inGrace,
                'shared_wallets' => $this->memberships->sharedWallets(),
            ]),
            'claims_awaiting' => $awaiting['count'],
            'oldest_claim_at' => $awaiting['oldest']?->format('Y-m-d\TH:i:s.uP'),
            'reenrolments_open' => $this->reenrolments->openCount(),
            'push' => $this->notifications->last24Hours(),
            'play' => $this->play->last24Hours(),
            'play_rejections' => $this->play->rejectedByReason($now),
        ];
    }
}
