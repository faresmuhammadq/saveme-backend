<?php

namespace App\Modules\Billing\Domain\GooglePlay;

use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use DateTimeImmutable;

/**
 * Google's state onto ADR-0013 rule 4 (spec 024, R-5, FR-020 to FR-029;
 * ADR-0026). Pure. Only drawn arcs are produced: `active → expired` is the
 * zero-length grace hop with one lapse event; `revoked` is terminal; the end
 * of a billing period never moves backwards (except by revocation, which
 * leaves it alone anyway); a state that would change nothing yields `none`.
 */
final class PlayStateMap
{
    public static function target(PlayPurchaseRecord $record, ?SubscriptionRecord $current, DateTimeImmutable $now, string $revokeReason = 'revoked_by_provider'): PlayTransition
    {
        if ($current?->status === SubscriptionStatus::Revoked) {
            return PlayTransition::none(ignored: $record->state !== PlayState::Revoked);
        }

        if ($record->kind === PlayProductKind::OneTime) {
            return match (true) {
                $record->state === PlayState::Active => self::toActive($record, $current, $now, null, false),
                $record->state === PlayState::Revoked,
                $record->state === PlayState::PendingCanceled && $current?->status->grants() === true => self::toRevoked($current, $revokeReason),
                default => PlayTransition::none(),
            };
        }

        return match ($record->state) {
            PlayState::Active => self::toActive($record, $current, $now, $record->expiresAt, $record->autoRenew),
            PlayState::Canceled => $record->expiresAt !== null && $record->expiresAt > $now
                ? self::toActive($record, $current, $now, $record->expiresAt, false)
                : self::toExpired($current, $now),
            PlayState::Grace => self::toGrace($record, $current, $now),
            PlayState::OnHold, PlayState::Paused, PlayState::Expired => self::toExpired($current, $now),
            PlayState::Revoked => self::toRevoked($current, $revokeReason),
            PlayState::Pending, PlayState::PendingCanceled => PlayTransition::none(),
        };
    }

    private static function toActive(PlayPurchaseRecord $record, ?SubscriptionRecord $current, DateTimeImmutable $now, ?DateTimeImmutable $end, bool $autoRenew): PlayTransition
    {
        if ($current === null) {
            return PlayTransition::make(true, SubscriptionStatus::Active, $record->purchasedAt, $end, null, $autoRenew, 'purchase', null, PlayTransition::EVENT_ACTIVATED);
        }

        $keptEnd = self::latest($current->currentPeriodEnd, $end, $current->currentPeriodEnd === null && $current->status === SubscriptionStatus::Active);

        if ($current->status === SubscriptionStatus::Active) {
            $endMoved = $keptEnd != $current->currentPeriodEnd;

            if (! $endMoved && $autoRenew === $current->autoRenew && $current->graceUntil === null) {
                return PlayTransition::none();
            }

            return PlayTransition::make(false, SubscriptionStatus::Active, $endMoved ? $current->currentPeriodEnd : $current->currentPeriodStart, $keptEnd, null, $autoRenew, $endMoved ? 'renew' : null);
        }

        // grace | expired → active: payment recovered, resumed or restarted (rule 4).
        $start = $current->status === SubscriptionStatus::Grace ? ($current->currentPeriodEnd ?? $now) : $now;

        return PlayTransition::make(false, SubscriptionStatus::Active, $start, $keptEnd, null, $autoRenew, 'recover', null, PlayTransition::EVENT_ACTIVATED);
    }

    private static function toGrace(PlayPurchaseRecord $record, ?SubscriptionRecord $current, DateTimeImmutable $now): PlayTransition
    {
        $graceUntil = $record->graceUntil ?? $record->expiresAt ?? $now;

        if ($current === null) {
            return PlayTransition::make(true, SubscriptionStatus::Grace, $record->purchasedAt, $record->expiresAt ?? $graceUntil, $graceUntil, true, 'purchase', null, PlayTransition::EVENT_ACTIVATED);
        }

        return match ($current->status) {
            SubscriptionStatus::Active => PlayTransition::make(false, SubscriptionStatus::Grace, $current->currentPeriodStart, $current->currentPeriodEnd, $graceUntil, true, null, null, PlayTransition::EVENT_LAPSED),
            SubscriptionStatus::Grace => $current->graceUntil !== null && $graceUntil <= $current->graceUntil
                ? PlayTransition::none()
                : PlayTransition::make(false, SubscriptionStatus::Grace, $current->currentPeriodStart, $current->currentPeriodEnd, $graceUntil, true),
            default => self::toActive($record, $current, $now, $record->expiresAt, true),
        };
    }

    /**
     * The transition that ends a granting subscription as expired: a purchase
     * replaced by another, or a Google that has been unreachable for too long.
     */
    public static function expiring(?SubscriptionRecord $current, DateTimeImmutable $now): PlayTransition
    {
        return self::toExpired($current, $now);
    }

    /**
     * The transition that revokes a granting subscription (a voided purchase).
     */
    public static function revoking(?SubscriptionRecord $current, string $reason): PlayTransition
    {
        return self::toRevoked($current, $reason);
    }

    private static function toExpired(?SubscriptionRecord $current, DateTimeImmutable $now): PlayTransition
    {
        if ($current === null || ! $current->status->grants()) {
            return PlayTransition::none();
        }

        return PlayTransition::make(
            false,
            SubscriptionStatus::Expired,
            $current->currentPeriodStart,
            $current->currentPeriodEnd,
            $current->status === SubscriptionStatus::Active ? $now : ($current->graceUntil ?? $now),
            $current->autoRenew,
            'expire',
            null,
            PlayTransition::EVENT_LAPSED,
            viaGraceHop: $current->status === SubscriptionStatus::Active,
        );
    }

    private static function toRevoked(?SubscriptionRecord $current, string $reason): PlayTransition
    {
        // Revoked is drawn from active and grace only: an expired subscription grants nothing already.
        if ($current === null || ! $current->status->grants()) {
            return PlayTransition::none();
        }

        return PlayTransition::make(false, SubscriptionStatus::Revoked, $current->currentPeriodStart, $current->currentPeriodEnd, $current->graceUntil, false, 'revoke', $reason, PlayTransition::EVENT_LAPSED);
    }

    /**
     * The later of two ends; null (lifetime) beats any end when $keepLifetime.
     */
    private static function latest(?DateTimeImmutable $stored, ?DateTimeImmutable $reported, bool $keepLifetime): ?DateTimeImmutable
    {
        if ($keepLifetime) {
            return null;
        }

        if ($stored === null) {
            return $reported;
        }

        if ($reported === null) {
            return $stored;
        }

        return max($stored, $reported);
    }
}
