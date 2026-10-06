<?php

namespace App\Modules\Notifications\Domain\Messages;

use InvalidArgumentException;

/**
 * Builds the data block of a push (spec 023, FR-020). The only gate between
 * a caller and the lock screen: a key outside the type's list, or a value
 * that is not a string, throws. Nothing is filtered or truncated silently.
 */
final class PayloadBuilder
{
    public const SCHEMA = '1';

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function assertShape(MessageType $type, array $data): void
    {
        $allowed = $type->allowedKeys();

        foreach ($data as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Payload key [{$key}] is not allowed for [{$type->value}].");
            }

            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("Payload value of [{$key}] must be a non-empty string.");
            }
        }

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("Payload key [{$key}] is required for [{$type->value}].");
            }
        }
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    public static function build(MessageType $type, string $notificationId, array $data): array
    {
        self::assertShape($type, $data);

        return [
            'schema' => self::SCHEMA,
            'type' => $type->value,
            'category' => $type->category()->value,
            'notification_id' => $notificationId,
            ...$data,
        ];
    }
}
