<?php

use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Notifications\Domain\Messages\PayloadBuilder;

$id = '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70';

test('every type builds only its own keys plus the four common ones, all strings', function () use ($id) {
    foreach (MessageType::cases() as $type) {
        $data = array_fill_keys($type->allowedKeys(), $type->value === 'member_role_changed' ? 'viewer' : $id);
        $payload = PayloadBuilder::build($type, $id, $data);

        expect(array_keys($payload))->toBe(['schema', 'type', 'category', 'notification_id', ...$type->allowedKeys()])
            ->and($payload['schema'])->toBe('1')
            ->and($payload['type'])->toBe($type->value)
            ->and($payload['category'])->toBe($type->category()->value)
            ->and($payload['notification_id'])->toBe($id)
            ->and(array_filter($payload, fn (mixed $value): bool => ! is_string($value)))->toBe([]);
    }
});

test('a key outside the type list throws, whatever it is', function (string $forbidden) use ($id) {
    expect(fn () => PayloadBuilder::build(MessageType::BudgetExceeded, $id, ['budget_id' => $id, $forbidden => 'x']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NotificationDraft($id, MessageType::BudgetExceeded, 'k', ['budget_id' => $id, $forbidden => 'x']))
        ->toThrow(InvalidArgumentException::class);
})->with(['amount_minor', 'name', 'note', 'date', 'url', 'token', 'currency', 'wallet_name', 'email', 'description']);

test('an identifier of another type is not allowed either', function () use ($id) {
    expect(fn () => PayloadBuilder::build(MessageType::BudgetExceeded, $id, ['budget_id' => $id, 'wallet_id' => $id]))
        ->toThrow(InvalidArgumentException::class);
});

test('a missing identifier throws', function () use ($id) {
    expect(fn () => PayloadBuilder::build(MessageType::OccurrenceDue, $id, ['wallet_id' => $id]))
        ->toThrow(InvalidArgumentException::class);
});

test('a value that is not a non-empty string throws', function () use ($id) {
    expect(fn () => PayloadBuilder::build(MessageType::BudgetExceeded, $id, ['budget_id' => 12]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayloadBuilder::build(MessageType::BudgetExceeded, $id, ['budget_id' => '']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayloadBuilder::build(MessageType::BudgetExceeded, $id, ['budget_id' => null]))->toThrow(InvalidArgumentException::class);
});
