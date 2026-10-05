<?php

use App\Modules\Admin\Application\Lookup\LookupBudget;
use App\Modules\Admin\Domain\Exceptions\LookupBudgetSpent;
use Illuminate\Support\Str;

/*
| 30 account lookups per 10 minutes per operator (spec 021, FR-061, R-9).
*/

it('allows 30 lookups and refuses the 31st with the minutes to wait', function () {
    $this->freezeTime();
    $operator = (string) Str::uuid();
    $budget = app(LookupBudget::class);

    for ($i = 0; $i < 30; $i++) {
        $budget->spend($operator);
    }

    try {
        $budget->spend($operator);
        $this->fail('The 31st lookup should be refused.');
    } catch (LookupBudgetSpent $spent) {
        expect($spent->minutes)->toBe(10);
    }
});

it('does not touch another operator', function () {
    $budget = app(LookupBudget::class);
    $first = (string) Str::uuid();

    for ($i = 0; $i < 30; $i++) {
        $budget->spend($first);
    }

    $budget->spend((string) Str::uuid());
    expect(true)->toBeTrue();
});

it('refills after the window', function () {
    $this->freezeTime();
    $operator = (string) Str::uuid();
    $budget = app(LookupBudget::class);

    for ($i = 0; $i < 30; $i++) {
        $budget->spend($operator);
    }

    $this->travel(601)->seconds();
    $budget->spend($operator);

    expect(true)->toBeTrue();
});

it('reports the remaining wait in whole minutes, rounded up', function () {
    $this->freezeTime();
    $operator = (string) Str::uuid();
    $budget = app(LookupBudget::class);

    for ($i = 0; $i < 30; $i++) {
        $budget->spend($operator);
    }

    $this->travel(5)->minutes();

    expect(fn () => $budget->assertAvailable($operator))->toThrow(fn (LookupBudgetSpent $spent): bool => $spent->minutes === 5);
});
