<?php

namespace App\Modules\Admin\Application\Accounts;

use App\Modules\Billing\Application\Entitlements\EntitlementSummary;
use App\Modules\Billing\Application\Subscriptions\AccessView;
use App\Modules\Identity\Application\Operators\AccountFacts;
use App\Modules\Wallets\Application\Membership\MemberCounts;

/**
 * Everything the account page shows about a person, and nothing it must not:
 * state, plan, subscription dates and counts (spec 021, FR-021, FR-022).
 * A purged account has its facts only; counts, access, plan and the push
 * device count are null. The push device count is how many phones can
 * receive notifications, nothing about them (spec 023, FR-046).
 */
final readonly class AccountSnapshot
{
    public function __construct(
        public AccountFacts $facts,
        public ?MemberCounts $counts,
        public ?AccessView $access,
        public ?EntitlementSummary $plan,
        public ?int $pushDevices = null,
    ) {}

    /**
     * @return array{facts: array<string, mixed>, counts: ?array<string, int>, access: ?array<string, mixed>, plan: ?array<string, mixed>, push_devices: ?int}
     */
    public function toArray(): array
    {
        return [
            'facts' => $this->facts->toArray(),
            'counts' => $this->counts?->toArray(),
            'access' => $this->access?->toArray(),
            'plan' => $this->plan === null ? null : self::planToArray($this->plan),
            'push_devices' => $this->pushDevices,
        ];
    }

    /**
     * @return array{id: string, name: array{ar: string, en: string}, features: array<string, bool|int|null>}
     */
    public static function planToArray(EntitlementSummary $plan): array
    {
        return ['id' => $plan->planId, 'name' => $plan->planName, 'features' => $plan->features];
    }
}
