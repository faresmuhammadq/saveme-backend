<?php

use App\Modules\Billing\Domain\PlanCode;

test('lower-case snake codes are accepted', function (string $code) {
    expect((new PlanCode($code))->value)->toBe($code);
})->with(['free', 'plus_monthly']);

test('anything else is refused', function (string $code) {
    expect(fn () => new PlanCode($code))->toThrow(InvalidArgumentException::class);
})->with(['Plus', 'plus-monthly', '', str_repeat('a', 41)]);
