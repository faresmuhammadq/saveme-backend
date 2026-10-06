<?php

namespace App\Modules\Notifications\Application\Delivery;

use App\Modules\Notifications\Domain\Messages\MessageType;

/**
 * The visible title and body of a push, rendered at send time in the
 * recipient's profile language from Notifications' translation files
 * (spec 023, FR-021, P-14). Fixed text only: nothing is interpolated.
 */
final class PushText
{
    /**
     * @return array{title: string, body: string}
     */
    public function render(MessageType $type, string $locale): array
    {
        return [
            'title' => (string) trans("notifications::push.{$type->value}.title", [], $locale),
            'body' => (string) trans("notifications::push.{$type->value}.body", [], $locale),
        ];
    }
}
