<?php

namespace App\Modules\Billing\Domain\GooglePlay;

use DateTimeImmutable;

/**
 * A Play Notification as stored in the inbox (data-model.md).
 */
final readonly class StoredPlayNotification
{
    public function __construct(
        public string $id,
        public string $messageId,
        public string $kind,
        public ?int $notificationType,
        public ?string $productId,
        public ?string $tokenLookup,
        public ?string $tokenSealed,
        public ?DateTimeImmutable $eventAt,
        public string $state,
        public ?string $ignoredReason,
        public int $attempts,
    ) {}

    public function isTerminal(): bool
    {
        return $this->state !== 'received';
    }
}
