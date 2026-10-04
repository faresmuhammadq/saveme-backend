<?php

namespace App\Modules\Billing\Application\RedeemCodes;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What redemption answers (spec 020, FR-031): the subscription it created (or,
 * on a repeat by the same account, the one it created earlier, as it now
 * stands), the governing subscription and the resulting entitlements. The
 * last two come from the same builders as `/me/subscription` and
 * `/me/entitlements`, so the shapes cannot drift. Never the claim, the code
 * or an address.
 */
final readonly class RedeemResult
{
    /**
     * @param  array<string, mixed>  $governing
     * @param  array<string, mixed>  $entitlements
     */
    public function __construct(
        public string $subscriptionId,
        public string $planId,
        public string $planName,
        public string $status,
        public DateTimeImmutable $currentPeriodStart,
        public ?DateTimeImmutable $currentPeriodEnd,
        public array $governing,
        public array $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'subscription' => [
                'id' => $this->subscriptionId,
                'plan' => ['id' => $this->planId, 'name' => $this->planName],
                'provider' => 'instapay',
                'status' => $this->status,
                'current_period_start' => self::instant($this->currentPeriodStart),
                'current_period_end' => $this->currentPeriodEnd === null ? null : self::instant($this->currentPeriodEnd),
            ],
            'governing' => $this->governing,
            'entitlements' => $this->entitlements,
        ];
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
