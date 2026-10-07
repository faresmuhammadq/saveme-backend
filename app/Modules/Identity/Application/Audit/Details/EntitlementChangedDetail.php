<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * An `entitlement_changed` entry (written by Billing, spec 009): what was
 * done to which plan, for how long, and the operator's reason. Never an
 * amount (security baseline, What an entry must never contain).
 */
final readonly class EntitlementChangedDetail implements AuditDetail
{
    /**
     * @param  'grant'|'extend'|'revoke'|'expire'|'redeem'|'purchase'|'renew'|'recover'  $action
     */
    public function __construct(
        private string $action,
        private string $planId,
        private ?int $days = null,
        private ?string $reason = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'action' => $this->action,
            'plan_id' => $this->planId,
            'days' => $this->days,
            'reason' => $this->reason === null ? null : mb_substr(trim($this->reason), 0, 500),
        ], fn (mixed $value): bool => $value !== null);
    }
}
