<?php

namespace App\Modules\Notifications\Domain\Messages;

/**
 * What a source asks Notifications to send: one message type for one person,
 * under a dedupe key unique per person, with only the identifiers of its
 * type (spec 023, FR-024).
 */
final readonly class NotificationDraft
{
    /**
     * @param  array<string, string>  $data  identifiers only, the keys allowed for the type
     */
    public function __construct(
        public string $userId,
        public MessageType $type,
        public string $dedupeKey,
        public array $data = [],
    ) {
        PayloadBuilder::assertShape($type, $data);
    }
}
