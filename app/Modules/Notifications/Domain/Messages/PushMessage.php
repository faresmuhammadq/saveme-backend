<?php

namespace App\Modules\Notifications\Domain\Messages;

/**
 * One message as the gateway sends it: generic localised text, the data
 * block, the Android channel (the category) and the collapse key (spec 023,
 * FR-019, FR-029, FR-038).
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public MessageType $type,
        public string $notificationId,
        public string $collapseKey,
        public string $title,
        public string $body,
        public array $data,
        public string $channel,
        public Priority $priority,
    ) {}
}
