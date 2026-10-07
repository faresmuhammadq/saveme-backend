<?php

namespace App\Modules\Billing\Domain\GooglePlay;

use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use DateTimeImmutable;

/**
 * What one Google read does to the local subscription (spec 024, R-5): the
 * complete target (status and dates) plus the audit action and the event it
 * earns. Built only by `PlayStateMap`.
 */
final readonly class PlayTransition
{
    public const EVENT_ACTIVATED = 'activated';

    public const EVENT_LAPSED = 'lapsed';

    /**
     * @param  'grant'|'extend'|'revoke'|'expire'|'redeem'|'purchase'|'renew'|'recover'|null  $auditAction
     */
    private function __construct(
        public bool $changed,
        public bool $create,
        public ?SubscriptionStatus $status,
        public bool $viaGraceHop,
        public ?DateTimeImmutable $periodStart,
        public ?DateTimeImmutable $periodEnd,
        public ?DateTimeImmutable $graceUntil,
        public bool $autoRenew,
        public ?string $auditAction,
        public ?string $auditReason,
        public ?string $event,
        public bool $ignored,
    ) {}

    public static function none(bool $ignored = false): self
    {
        return new self(false, false, null, false, null, null, null, false, null, null, null, $ignored);
    }

    /**
     * @param  'grant'|'extend'|'revoke'|'expire'|'redeem'|'purchase'|'renew'|'recover'|null  $auditAction
     */
    public static function make(
        bool $create,
        SubscriptionStatus $status,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        ?DateTimeImmutable $graceUntil,
        bool $autoRenew,
        ?string $auditAction = null,
        ?string $auditReason = null,
        ?string $event = null,
        bool $viaGraceHop = false,
    ): self {
        return new self(true, $create, $status, $viaGraceHop, $periodStart, $periodEnd, $graceUntil, $autoRenew, $auditAction, $auditReason, $event, false);
    }
}
