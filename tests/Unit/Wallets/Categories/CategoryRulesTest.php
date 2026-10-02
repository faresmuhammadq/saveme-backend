<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\CategoryRules;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryFieldImmutable;

function aCategoryRecord(string $name = 'Coffee'): CategoryRecord
{
    $now = new DateTimeImmutable('2026-10-02T08:00:00Z');

    return new CategoryRecord('0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70', '0192f79e-0000-7000-8000-000000000001', CategoryKind::Expense, $name, 'cup', '#6D4C41', null, 1, 3, $now, $now, null);
}

test('a category has exactly the two kinds', function () {
    expect(array_map(fn (CategoryKind $kind): string => $kind->value, CategoryKind::cases()))->toBe(['income', 'expense']);
});

test('a kind change is refused at /kind', function () {
    expect(fn () => CategoryRules::assertKindUnchanged(aCategoryRecord(), CategoryKind::Income))
        ->toThrow(function (CategoryFieldImmutable $refusal): void {
            expect($refusal->errorCode)->toBe(ErrorCode::ValidationFailed)
                ->and($refusal->fieldErrors[0]->pointer)->toBe('/kind')
                ->and($refusal->fieldErrors[0]->code)->toBe(ErrorCode::ImmutableField);
        });

    CategoryRules::assertKindUnchanged(aCategoryRecord(), CategoryKind::Expense);
});

test('a name is 1 to 50 characters after trimming', function (string $name, bool $valid) {
    expect(CategoryRules::isValidName($name))->toBe($valid);
})->with([
    'one character' => ['a', true],
    'fifty characters' => [str_repeat('a', 50), true],
    'fifty arabic characters' => [str_repeat('ب', 50), true],
    'fifty-one characters' => [str_repeat('a', 51), false],
    'blank' => ['   ', false],
    'empty' => ['', false],
    'padded to fifty' => ['  '.str_repeat('a', 50).'  ', true],
]);

test('identity compares the trimmed name', function () {
    $record = aCategoryRecord('Coffee');

    expect(CategoryRules::isIdentical($record, ['kind' => 'expense', 'name' => '  Coffee ', 'icon' => 'cup', 'color' => '#6D4C41']))->toBeTrue()
        ->and(CategoryRules::isIdentical($record, ['kind' => 'expense', 'name' => 'Tea', 'icon' => 'cup', 'color' => '#6D4C41']))->toBeFalse()
        ->and(CategoryRules::isIdentical($record, ['kind' => 'income', 'name' => 'Coffee', 'icon' => 'cup', 'color' => '#6D4C41']))->toBeFalse();
});
