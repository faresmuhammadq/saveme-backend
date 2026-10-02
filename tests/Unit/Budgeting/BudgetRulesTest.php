<?php

use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Domain\BudgetRules;
use App\Modules\Budgeting\Domain\PeriodType;
use App\Modules\Budgeting\Domain\Refusals\BudgetStateInvalid;
use App\Modules\Shared\Domain\Errors\ErrorCode;

/*
 * The state rules of a budget write (spec 015, FR-002, FR-006).
 */

function aStoredBudget(): BudgetRecord
{
    $now = new DateTimeImmutable('2026-10-01T08:00:00Z');

    return new BudgetRecord('0192f7e0-0000-7000-8000-0000000000b1', '0192f79e-0000-7000-8000-000000000001', '0192f7a1-0000-7000-8000-0000000000c5', PeriodType::Monthly, 600000, 'EGP', null, 1, 3, $now, $now, null);
}

function storedBudgetValues(array $changes = []): array
{
    return [...aStoredBudget()->writableValues(), ...$changes];
}

function immutableBudgetErrors(array $changes): array
{
    try {
        BudgetRules::assertImmutablesUnchanged(aStoredBudget(), storedBudgetValues($changes));
    } catch (BudgetStateInvalid $refusal) {
        return array_map(fn ($error) => $error->pointer.' '.$error->code->value, $refusal->fieldErrors);
    }

    return [];
}

test('unchanged members pass, and a changed limit is not an immutable member', function () {
    expect(immutableBudgetErrors([]))->toBe([])
        ->and(immutableBudgetErrors(['amount_minor' => 700000]))->toBe([]);
});

test('a changed category, currency or period type is IMMUTABLE_FIELD at its pointer', function (array $change, string $expected) {
    expect(immutableBudgetErrors($change))->toBe([$expected]);
})->with([
    'category' => [['category_id' => '0192f7a1-0000-7000-8000-0000000000c6'], '/category_id IMMUTABLE_FIELD'],
    'all expenses' => [['category_id' => null], '/category_id IMMUTABLE_FIELD'],
    'currency' => [['currency' => 'USD'], '/currency IMMUTABLE_FIELD'],
    'period type' => [['period_type' => 'weekly'], '/period_type IMMUTABLE_FIELD'],
]);

test('every changed immutable member is reported together', function () {
    expect(immutableBudgetErrors(['category_id' => null, 'currency' => 'USD', 'period_type' => 'weekly']))
        ->toBe(['/category_id IMMUTABLE_FIELD', '/currency IMMUTABLE_FIELD', '/period_type IMMUTABLE_FIELD']);
});

test('a write is identical when every writable member equals the stored one', function () {
    expect(BudgetRules::isIdentical(aStoredBudget(), storedBudgetValues()))->toBeTrue()
        ->and(BudgetRules::isIdentical(aStoredBudget(), storedBudgetValues(['amount_minor' => 1])))->toBeFalse();
});

test('monthly is the only Budget Period type', function () {
    expect(BudgetRules::periodTypes())->toBe(['monthly'])
        ->and(PeriodType::tryFrom('weekly'))->toBeNull();
});

test('the refusal is a validation failure', function () {
    try {
        BudgetRules::assertImmutablesUnchanged(aStoredBudget(), storedBudgetValues(['currency' => 'USD']));
    } catch (BudgetStateInvalid $refusal) {
        expect($refusal->errorCode)->toBe(ErrorCode::ValidationFailed);

        return;
    }

    throw new RuntimeException('Expected a refusal.');
});
