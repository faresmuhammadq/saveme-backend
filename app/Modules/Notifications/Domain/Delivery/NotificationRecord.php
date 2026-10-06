<?php

namespace App\Modules\Notifications\Domain\Delivery;

use App\Modules\Notifications\Domain\Messages\MessageType;
use DateTimeImmutable;

/**
 * A Push Notification as the application knows it: identifiers and states,
 * never text and never the token (spec 023, data-model).
 */
final readonly class NotificationRecord
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $id,
        public string $userId,
        public MessageType $type,
        public string $dedupeKey,
        public array $data,
        public string $collapseKey,
        public NotificationState $state,
        public ?SkipReason $skipReason,
        public ?DateTimeImmutable $notBefore,
        public ?DateTimeImmutable $sendingSince,
        public ?DateTimeImmutable $sentAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
