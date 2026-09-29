<?php

namespace App\Modules\Billing\Domain\Subscriptions;

use DateTimeImmutable;

/**
 * Which subscription answers for a person (ADR-0013 rule 14): among those
 * still granting at $now, the one whose billing period ends latest; no end
 * beats any end; ties go to the most recently created, then the highest id.
 * None means the default (free) plan answers.
 */
final class GoverningSubscription
{
    /**
     * @param  list<SubscriptionRecord>  $subscriptions
     */
    public static function among(array $subscriptions, DateTimeImmutable $now): ?SubscriptionRecord
    {
        $granting = array_values(array_filter($subscriptions, fn (SubscriptionRecord $s): bool => $s->grantsAt($now)));

        usort($granting, function (SubscriptionRecord $a, SubscriptionRecord $b): int {
            return [self::endKey($b), $b->createdAt->format('U.u'), $b->id] <=> [self::endKey($a), $a->createdAt->format('U.u'), $a->id];
        });

        return $granting[0] ?? null;
    }

    /**
     * Lifetime sorts after every dated end.
     */
    private static function endKey(SubscriptionRecord $subscription): string
    {
        return $subscription->currentPeriodEnd?->format('Y-m-d H:i:s.u') ?? '9999-12-31 23:59:59.999999';
    }
}
