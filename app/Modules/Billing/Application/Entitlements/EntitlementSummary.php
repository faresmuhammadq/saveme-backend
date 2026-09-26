<?php

namespace App\Modules\Billing\Application\Entitlements;

/**
 * What a person's effective plan lets them do, as reported to the app.
 * The plan label is a translation key for display only; clients gate on
 * `features`, never on the label.
 */
final readonly class EntitlementSummary
{
    /**
     * @param  array<string, bool|int>  $features
     */
    public function __construct(
        public string $planLabelKey,
        public array $features,
    ) {}
}
