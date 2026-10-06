<?php

namespace App\Modules\Notifications\Domain\Delivery;

/**
 * One Push Delivery: one notification to one registration.
 */
final readonly class DeliveryRecord
{
    public function __construct(
        public string $id,
        public string $notificationId,
        public string $registrationId,
        public DeliveryState $state,
        public int $attempts,
        public ?string $lastError,
    ) {}
}
