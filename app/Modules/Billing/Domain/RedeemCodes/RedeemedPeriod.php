<?php

namespace App\Modules\Billing\Domain\RedeemCodes;

use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The billing period a redemption creates (spec 020, FR-029, R-8). It starts
 * at the later of now and the latest dated end among the person's granting
 * `instapay` subscriptions (an early renewal loses no days; Play and manual
 * periods never chain), and lasts one plan period: a calendar month or year
 * without overflow (Jan 31 + 1 month = Feb 28 or 29), `period_days` days, or
 * no end for a lifetime plan. Instants are UTC (P-12).
 */
final readonly class RedeemedPeriod
{
    private function __construct(
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
    ) {}

    /**
     * @param  list<SubscriptionRecord>  $subscriptions  all of the person's subscriptions
     */
    public static function for(string $periodType, ?int $periodDays, DateTimeImmutable $now, array $subscriptions): self
    {
        $start = $now->setTimezone(new DateTimeZone('UTC'));

        foreach ($subscriptions as $subscription) {
            $end = $subscription->currentPeriodEnd;

            if ($subscription->provider === 'instapay' && $subscription->grantsAt($now) && $end !== null && $end > $start) {
                $start = $end->setTimezone(new DateTimeZone('UTC'));
            }
        }

        $end = match ($periodType) {
            'monthly' => self::addMonths($start, 1),
            'yearly' => self::addMonths($start, 12),
            'custom' => $start->modify('+'.(int) $periodDays.' days'),
            default => null,
        };

        return new self($start, $end);
    }

    private static function addMonths(DateTimeImmutable $at, int $months): DateTimeImmutable
    {
        $first = $at->modify('first day of this month')->modify("+{$months} months");
        $day = min((int) $at->format('j'), (int) $first->format('t'));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    }
}
