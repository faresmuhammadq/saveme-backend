<?php

namespace App\Modules\Notifications\Application\Delivery;

use App\Modules\Identity\Application\Profiles\RecipientProfile;
use App\Modules\Notifications\Application\PreferencesService;
use App\Modules\Notifications\Domain\Delivery\DeliveryPolicy;
use App\Modules\Notifications\Domain\Delivery\NotificationRecord;
use App\Modules\Notifications\Domain\Delivery\PolicyDecision;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use DateTimeImmutable;

/**
 * Gathers what the pure DeliveryPolicy needs (current preferences, the
 * recipient's timezone and standing, recent sends) and asks it. Shared by the
 * creator, the delivery job and the release sweep, so one rule applies at
 * creation, at send and at release (spec 023, R-8).
 */
final class DeliveryDecider
{
    public function __construct(
        private readonly DeliveryPolicy $policy,
        private readonly PreferencesService $preferences,
        private readonly RecipientProfile $recipients,
        private readonly NotificationRepository $notifications,
    ) {}

    public function decide(NotificationRecord $notification, DateTimeImmutable $now): PolicyDecision
    {
        $recipient = $this->recipients->of($notification->userId);

        return $this->policy->decide(
            $this->preferences->effective($notification->userId),
            $recipient->timezone,
            $recipient->active,
            $notification->type->category(),
            $now,
            $this->notifications->sentCountSince($notification->userId, $now->modify('-1 hour'), $notification->id),
            $this->notifications->sentCountSince($notification->userId, $now->modify('-1 day'), $notification->id),
        );
    }
}
