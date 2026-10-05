<?php

namespace App\Modules\Admin\Application\Overview;

use App\Modules\Admin\Application\Reenrolment\ReenrolmentQuery;
use App\Modules\Admin\Domain\AnonymityFloor;
use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Application\Figures\BillingFigures;
use App\Modules\Identity\Application\Figures\AccountFigures;
use App\Modules\Wallets\Application\Membership\MembershipCounts;

/**
 * The overview's figures (spec 021, FR-010 to FR-013, R-11): population
 * counts pass through the anonymity floor, the needs-attention figures are
 * exact. Computed on each call; nothing is stored and nothing is audited.
 */
final class OverviewFigures
{
    public function __construct(
        private readonly AccountFigures $accounts,
        private readonly BillingFigures $billing,
        private readonly MembershipCounts $memberships,
        private readonly ClaimQuery $claims,
        private readonly ReenrolmentQuery $reenrolments,
    ) {}

    /**
     * @return array{
     *     figures: array<string, int|string>,
     *     claims_awaiting: int,
     *     oldest_claim_at: ?string,
     *     reenrolments_open: int,
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
                'paid_instapay' => $billing->paidInstapay,
                'granted_manual' => $billing->grantedManual,
                'in_grace' => $billing->inGrace,
                'shared_wallets' => $this->memberships->sharedWallets(),
            ]),
            'claims_awaiting' => $awaiting['count'],
            'oldest_claim_at' => $awaiting['oldest']?->format('Y-m-d\TH:i:s.uP'),
            'reenrolments_open' => $this->reenrolments->openCount(),
        ];
    }
}
