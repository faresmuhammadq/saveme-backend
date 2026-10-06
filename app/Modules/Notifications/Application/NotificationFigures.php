<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Ports\DeliveryRepository;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use App\Modules\Notifications\Domain\Ports\RegistrationRepository;

/**
 * What operators may see of push (spec 023, FR-046, FR-047): how many devices
 * of an account can receive it, and platform-wide counts for the last 24
 * hours. Counts only: never a token, a text, a payload or a per-person list.
 */
final class NotificationFigures
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly NotificationRepository $notifications,
        private readonly DeliveryRepository $deliveries,
    ) {}

    public function registrationCountFor(string $userId): int
    {
        return $this->registrations->countFor($userId);
    }

    /**
     * @return array{created: int, sent: int, skipped: int, failed: int, pruned: int}
     */
    public function last24Hours(): array
    {
        $since = now()->toDateTimeImmutable()->modify('-24 hours');

        return [...$this->notifications->figuresSince($since), 'pruned' => $this->deliveries->prunedSince($since)];
    }
}
