<?php

namespace App\Modules\Billing\Domain\Claims;

use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;

/**
 * What a claim must say about money (spec 020, FR-004, FR-005; P-03): the
 * plan is active, not the default, and has an `instapay` price; the currency
 * is that price's and the amount equals it exactly, in integer minor units.
 */
final class ClaimPrice
{
    /**
     * The plan's InstaPay price, or null when it cannot be claimed.
     *
     * @return array{amount_minor: int, currency: string}|null
     */
    public static function of(?PlanRecord $plan): ?array
    {
        if ($plan === null || $plan->isDefault || $plan->status !== 'active') {
            return null;
        }

        foreach ($plan->prices as $price) {
            if ($price['channel'] === 'instapay') {
                return ['amount_minor' => $price['amount_minor'], 'currency' => $price['currency']];
            }
        }

        return null;
    }

    /**
     * A member that is null is not compared (it failed an earlier rule).
     *
     * @return list<FieldError> empty when the claim states the plan's exact price
     */
    public static function check(?PlanRecord $plan, ?int $amountMinor, ?string $currency): array
    {
        $price = self::of($plan);

        if ($price === null) {
            return [new FieldError('/plan_id', ErrorCode::InvalidEnumValue)];
        }

        $errors = [];

        if ($currency !== null && $currency !== $price['currency']) {
            $errors[] = new FieldError('/currency', ErrorCode::CurrencyMismatch);
        }

        if ($amountMinor !== null && $amountMinor !== $price['amount_minor']) {
            $errors[] = new FieldError('/amount_minor', ErrorCode::OutOfRange);
        }

        return $errors;
    }
}
