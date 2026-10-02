<?php

namespace App\Modules\Wallets\Domain\Recurring;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The amounts a rule gives its occurrences (spec 014, FR-012, research
 * R-10). A per-occurrence rule gives every round the rule's amount. A rule
 * stating a total splits it over its rounds with `Money::allocate()`, so the
 * parts sum exactly to the total, the remainder going to the earliest rounds.
 * A gam'eya payout is the sum of every round's scheduled amount; skips and
 * edits of single occurrences never move it.
 */
final class OccurrenceAmounts
{
    /**
     * The scheduled amount of a round (1-based).
     */
    public static function forRound(AmountBasis $basis, int $amountMinor, string $currency, ?int $count, int $ordinal): int
    {
        if ($basis === AmountBasis::PerOccurrence || $count === null) {
            return $amountMinor;
        }

        $shares = Money::of($amountMinor, $currency)->allocate($count);

        return ($shares[$ordinal - 1] ?? $shares[count($shares) - 1])->amountMinor;
    }

    /**
     * The sum of every round's scheduled amount. May exceed the largest
     * amount a client holds exactly; the caller refuses that.
     */
    public static function payout(AmountBasis $basis, int $amountMinor, ?int $count): int
    {
        return $basis === AmountBasis::Total || $count === null ? $amountMinor : $amountMinor * $count;
    }
}
