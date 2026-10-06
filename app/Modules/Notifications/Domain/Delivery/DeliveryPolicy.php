<?php

namespace App\Modules\Notifications\Domain\Delivery;

use App\Modules\Notifications\Domain\Preferences\Category;
use App\Modules\Notifications\Domain\Preferences\Preferences;
use DateTimeImmutable;

/**
 * The pure rule shared by the creator and the delivery job (spec 023, R-8):
 * nothing is sent to an account that is leaving for good, for a category the
 * person turned off, inside their quiet hours (it is held until they end), or
 * past the rate limit.
 */
final class DeliveryPolicy
{
    public function __construct(
        private readonly int $perHour,
        private readonly int $perDay,
    ) {}

    public function decide(
        Preferences $preferences,
        string $timezone,
        bool $accountActive,
        Category $category,
        DateTimeImmutable $now,
        int $sentLastHour,
        int $sentLastDay,
    ): PolicyDecision {
        if (! $accountActive) {
            return PolicyDecision::skip(SkipReason::InactiveAccount);
        }

        if (! $preferences->allows($category)) {
            return PolicyDecision::skip(SkipReason::CategoryOff);
        }

        if ($preferences->quietHours->contains($now, $timezone)) {
            return PolicyDecision::hold($preferences->quietHours->endAfter($now, $timezone));
        }

        if ($sentLastHour >= $this->perHour || $sentLastDay >= $this->perDay) {
            return PolicyDecision::skip(SkipReason::RateLimited);
        }

        return PolicyDecision::send();
    }
}
