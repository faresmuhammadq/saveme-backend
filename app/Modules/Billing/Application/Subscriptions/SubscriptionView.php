<?php

namespace App\Modules\Billing\Application\Subscriptions;

use DateTimeImmutable;

/**
 * One subscription as an operator sees it: plan name, channel, status and
 * dates, and what an operator may do to it. No price and no provider
 * reference (spec 021, R-10). A Google Play one also shows its auto-renew,
 * acknowledged and test markers (spec 024, FR-052): never a token, an order
 * id or a reference.
 */
final readonly class SubscriptionView
{
    /**
     * @param  array{ar: string, en: string}  $planName
     */
    public function __construct(
        public string $id,
        public string $planId,
        public array $planName,
        public string $provider,
        public string $status,
        public DateTimeImmutable $periodStart,
        public ?DateTimeImmutable $periodEnd,
        public ?DateTimeImmutable $graceUntil,
        public DateTimeImmutable $createdAt,
        public bool $canExtend,
        public bool $canRevoke,
        public bool $autoRenew = false,
        public ?bool $playAcknowledged = null,
        public ?bool $playTest = null,
    ) {}

    /**
     * @return array{id: string, plan_id: string, plan_name: array{ar: string, en: string}, provider: string, status: string, period_start: string, period_end: ?string, grace_until: ?string, created_at: string, can_extend: bool, can_revoke: bool, auto_renew: bool, play_acknowledged: ?bool, play_test: ?bool}
     */
    public function toArray(): array
    {
        $instant = 'Y-m-d\TH:i:s.uP';

        return [
            'id' => $this->id,
            'plan_id' => $this->planId,
            'plan_name' => $this->planName,
            'provider' => $this->provider,
            'status' => $this->status,
            'period_start' => $this->periodStart->format($instant),
            'period_end' => $this->periodEnd?->format($instant),
            'grace_until' => $this->graceUntil?->format($instant),
            'created_at' => $this->createdAt->format($instant),
            'can_extend' => $this->canExtend,
            'can_revoke' => $this->canRevoke,
            'auto_renew' => $this->autoRenew,
            'play_acknowledged' => $this->playAcknowledged,
            'play_test' => $this->playTest,
        ];
    }
}
