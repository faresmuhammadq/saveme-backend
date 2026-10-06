<?php

namespace App\Modules\Notifications\Domain\Ports;

use App\Modules\Notifications\Domain\Delivery\DeliveryRecord;
use App\Modules\Notifications\Domain\Delivery\DeliveryState;
use DateTimeImmutable;

interface DeliveryRepository
{
    /**
     * The notification's deliveries, creating a pending one for each given
     * registration that has none yet.
     *
     * @param  list<string>  $registrationIds
     * @return list<DeliveryRecord>
     */
    public function ensureFor(string $notificationId, array $registrationIds): array;

    /**
     * @return list<DeliveryRecord>
     */
    public function forNotification(string $notificationId): array;

    /**
     * Records an attempt: bumps the count and sets the state and the
     * provider's error code (never a body).
     */
    public function record(string $deliveryId, DeliveryState $state, ?string $error): void;

    /**
     * How many registrations were pruned since the instant.
     */
    public function prunedSince(DateTimeImmutable $since): int;
}
