<?php

namespace App\Modules\Billing\Application\Entitlements;

/**
 * What a person's governing plan lets them do, as reported to the app. The
 * plan is named by id and name only; clients gate on `features`, never on
 * the plan (ADR-0013 rule 6).
 */
final readonly class EntitlementSummary
{
    /**
     * @param  array{ar: string, en: string}  $planName
     * @param  array<string, bool|int|null>  $features
     */
    public function __construct(
        public string $planId,
        public array $planName,
        public array $features,
    ) {}

    /**
     * The one wire shape, shared by sign-in (Identity) and /me/entitlements.
     *
     * @return array{plan: array{id: string, name: string}, features: object}
     */
    public function toWire(string $locale): array
    {
        return [
            'plan' => ['id' => $this->planId, 'name' => $locale === 'ar' ? $this->planName['ar'] : $this->planName['en']],
            'features' => (object) $this->features,
        ];
    }
}
