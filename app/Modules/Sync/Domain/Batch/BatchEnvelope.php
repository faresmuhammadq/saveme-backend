<?php

namespace App\Modules\Sync\Domain\Batch;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Sync\Domain\Refusals\EnvelopeInvalid;

/**
 * Validates the shape of a sync batch and nothing inside an item's `data`
 * (spec 013, R-4): the single write answers for its own body. An unusable
 * envelope is refused whole, never half applied.
 */
final class BatchEnvelope
{
    public const MIN_ITEMS = 1;

    public const MAX_ITEMS = 50;

    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const ITEM_MEMBERS = ['type', 'op', 'id', 'wallet_id', 'data'];

    /**
     * @param  array<array-key, mixed>  $body  the decoded JSON object
     * @return list<BatchItem>
     *
     * @throws EnvelopeInvalid with every fault found
     */
    public static function parse(array $body): array
    {
        $errors = [];

        foreach (array_keys($body) as $member) {
            if ($member !== 'items') {
                $errors[] = new FieldError(self::pointer([(string) $member]), ErrorCode::UnknownField);
            }
        }

        $items = [];

        if (! array_key_exists('items', $body)) {
            $errors[] = new FieldError('/items', ErrorCode::Required);
        } elseif (! is_array($body['items']) || ($body['items'] !== [] && ! array_is_list($body['items']))) {
            $errors[] = new FieldError('/items', ErrorCode::InvalidType);
        } elseif (count($body['items']) < self::MIN_ITEMS || count($body['items']) > self::MAX_ITEMS) {
            $errors[] = new FieldError('/items', ErrorCode::OutOfRange);
        } else {
            foreach ($body['items'] as $index => $raw) {
                $item = self::item($index, $raw, $errors);

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        if ($errors !== []) {
            throw new EnvelopeInvalid($errors);
        }

        return $items;
    }

    /**
     * @param  list<FieldError>  $errors
     */
    private static function item(int $index, mixed $raw, array &$errors): ?BatchItem
    {
        $at = fn (string ...$path): string => self::pointer(['items', (string) $index, ...array_values($path)]);

        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors[] = new FieldError($at(), ErrorCode::InvalidType);

            return null;
        }

        $before = count($errors);

        foreach (array_keys($raw) as $member) {
            if (! in_array($member, self::ITEM_MEMBERS, true)) {
                $errors[] = new FieldError($at((string) $member), ErrorCode::UnknownField);
            }
        }

        $type = null;
        if (! array_key_exists('type', $raw)) {
            $errors[] = new FieldError($at('type'), ErrorCode::Required);
        } elseif (! is_string($raw['type']) || ($type = ItemType::tryFrom($raw['type'])) === null) {
            $errors[] = new FieldError($at('type'), ErrorCode::InvalidEnumValue);
        }

        $operation = null;
        if (! array_key_exists('op', $raw)) {
            $errors[] = new FieldError($at('op'), ErrorCode::Required);
        } elseif (! is_string($raw['op']) || ($operation = ItemOperation::tryFrom($raw['op'])) === null || ($type !== null && ! $type->allows($operation))) {
            $errors[] = new FieldError($at('op'), ErrorCode::InvalidEnumValue);
            $operation = null;
        }

        $id = null;
        if (! array_key_exists('id', $raw)) {
            $errors[] = new FieldError($at('id'), ErrorCode::Required);
        } elseif (! self::isId($raw['id'])) {
            $errors[] = new FieldError($at('id'), ErrorCode::InvalidId);
        } else {
            $id = $raw['id'];
        }

        $walletId = null;
        if ($type !== null && ! $type->needsWalletId()) {
            if (array_key_exists('wallet_id', $raw)) {
                $errors[] = new FieldError($at('wallet_id'), ErrorCode::UnknownField);
            }
        } elseif (array_key_exists('wallet_id', $raw)) {
            if (! self::isId($raw['wallet_id'])) {
                $errors[] = new FieldError($at('wallet_id'), ErrorCode::InvalidId);
            } else {
                $walletId = $raw['wallet_id'];
            }
        } elseif ($type !== null) {
            $errors[] = new FieldError($at('wallet_id'), ErrorCode::Required);
        }

        $data = [];
        if (! array_key_exists('data', $raw)) {
            $errors[] = new FieldError($at('data'), ErrorCode::Required);
        } elseif (! is_array($raw['data']) || ($raw['data'] !== [] && array_is_list($raw['data']))) {
            $errors[] = new FieldError($at('data'), ErrorCode::InvalidType);
        } else {
            $data = $raw['data'];
        }

        if (count($errors) > $before || $type === null || $operation === null || $id === null) {
            return null;
        }

        return new BatchItem($type, $operation, $id, $walletId, $data);
    }

    private static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID_V7, $value) === 1;
    }

    /**
     * An RFC 6901 pointer.
     *
     * @param  list<string>  $segments
     */
    private static function pointer(array $segments): string
    {
        return '/'.implode('/', array_map(fn (string $segment): string => str_replace(['~', '/'], ['~0', '~1'], $segment), $segments));
    }
}
