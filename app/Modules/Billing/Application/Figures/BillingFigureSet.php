<?php

namespace App\Modules\Billing\Application\Figures;

/**
 * People with access on a non-default plan, by channel, and subscriptions in
 * grace. Counts only; a grant is never revenue (ADR-0013 rule 2). A Google Play
 * license tester's purchase is a test, never counted as paid (spec 024, FR-053).
 */
final readonly class BillingFigureSet
{
    public function __construct(
        public int $paidGooglePlay,
        public int $testGooglePlay,
        public int $paidInstapay,
        public int $grantedManual,
        public int $inGrace,
    ) {}
}
