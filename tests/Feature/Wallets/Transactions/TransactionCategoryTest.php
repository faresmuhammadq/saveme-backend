<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionStateInvalid;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Str;

/*
 * The category rules of a write (spec 012, FR-004, R-7, US1 S4-S6): the
 * caller's own or a platform category, live, of the transaction's kind;
 * archived ones are accepted; every failure is one INVALID_ENUM_VALUE.
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id);
});

/**
 * @param  array<string, mixed>  $body
 * @return list<array{pointer: string, code: string}>
 */
function refusedCategory(string $userId, string $walletId, array $body): array
{
    try {
        saveTransaction($userId, $walletId, (string) Str::uuid7(), $body);
    } catch (TransactionStateInvalid $refusal) {
        return array_map(fn ($error): array => ['pointer' => $error->pointer, 'code' => $error->code->value], $refusal->fieldErrors);
    }

    return [];
}

test('an own category and a platform category are accepted', function () {
    $own = ownedCategory($this->owner->id);

    expect(refusedCategory($this->owner->id, $this->wallet->id, transactionBody(['category_id' => $own->id])))->toBe([])
        ->and(refusedCategory($this->owner->id, $this->wallet->id, transactionBody(['category_id' => platformCategoryId()])))->toBe([])
        ->and(refusedCategory($this->owner->id, $this->wallet->id, incomeBody(['category_id' => platformCategoryId('income')])))->toBe([]);
});

test('another person\'s, a deleted, an unknown and a wrong-kind category are one invalid enum value', function (string $case) {
    $stranger = person('grace@example.test');
    $category = match ($case) {
        'another person\'s' => ownedCategory($stranger->id)->id,
        'deleted' => ownedCategory($this->owner->id, ['deleted_at' => now()])->id,
        'unknown' => (string) Str::uuid7(),
        'wrong kind' => ownedCategory($this->owner->id, ['kind' => 'income'])->id,
        'wrong platform kind' => platformCategoryId('income'),
    };

    expect(refusedCategory($this->owner->id, $this->wallet->id, transactionBody(['category_id' => $category])))
        ->toBe([['pointer' => '/category_id', 'code' => ErrorCode::InvalidEnumValue->value]]);
})->with(['another person\'s', 'deleted', 'unknown', 'wrong kind', 'wrong platform kind']);

test('an archived category and a retired platform category are accepted', function () {
    $archived = ownedCategory($this->owner->id, ['archived_at' => now()]);
    $platform = Category::query()->whereNull('user_id')->where('kind', 'expense')->first();
    $platform->forceFill(['archived_at' => now()])->save();

    expect(refusedCategory($this->owner->id, $this->wallet->id, transactionBody(['category_id' => $archived->id])))->toBe([])
        ->and(refusedCategory($this->owner->id, $this->wallet->id, transactionBody(['category_id' => $platform->id])))->toBe([]);
});

test('a category on a transfer and a missing category are refused', function () {
    $other = ownedWallet($this->owner->id);

    expect(refusedCategory($this->owner->id, $this->wallet->id, transferBody($other->id, ['category_id' => platformCategoryId()])))
        ->toBe([['pointer' => '/category_id', 'code' => ErrorCode::InvalidEnumValue->value]])
        ->and(refusedCategory($this->owner->id, $this->wallet->id, transactionBody(['category_id' => null])))
        ->toBe([['pointer' => '/category_id', 'code' => ErrorCode::Required->value]]);
});
