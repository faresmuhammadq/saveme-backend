<?php

use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;

/*
 * The redeem code's format (spec 020, FR-022, FR-023, R-7).
 */

test('a generated code is 16 characters of the Crockford alphabet, never I, L, O or U', function () {
    $seen = [];

    foreach (range(1, 1000) as $ignored) {
        $code = RedeemCode::generate();
        $seen[$code->value] = true;

        expect($code->value)->toMatch('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{16}$/');
    }

    expect($seen)->toHaveCount(1000);
});

test('a code is shown as four groups of four', function () {
    expect(RedeemCode::tryParse('7kq2mx4d9trbh3we')->display())->toBe('7KQ2-MX4D-9TRB-H3WE');
});

test('input is read loosely: case, hyphens and spaces are ignored and look-alikes are folded', function (string $typed) {
    expect(RedeemCode::tryParse($typed)?->value)->toBe('7KQ2MX4D9TRBH3WE');
})->with([
    'as mailed' => ['7KQ2-MX4D-9TRB-H3WE'],
    'lower case' => ['7kq2-mx4d-9trb-h3we'],
    'no hyphens' => ['7kq2mx4d9trbh3we'],
    'spaces' => [' 7KQ2 MX4D 9TRB H3WE '],
    'mixed separators' => ['7KQ2 MX4D-9TRB H3WE'],
]);

test('O reads as 0 and I and L read as 1', function () {
    expect(RedeemCode::tryParse('O0O0-IL1I-L1IL-OOOO')?->value)->toBe('0000111111110000')
        ->and(RedeemCode::tryParse('0OOO-IIII-LLLL-1111')?->value)->toBe('0000111111111111');
});

test('anything that is not 16 code characters is refused', function (string $typed) {
    expect(RedeemCode::tryParse($typed))->toBeNull();
})->with([
    '15 characters' => ['7KQ2-MX4D-9TRB-H3W'],
    '17 characters' => ['7KQ2-MX4D-9TRB-H3WEE'],
    'a U' => ['7KQ2-MX4D-9TRB-H3WU'],
    'a symbol' => ['7KQ2-MX4D-9TRB-H3W!'],
    'an underscore' => ['7KQ2_MX4D_9TRB_H3WE'],
    'empty' => [''],
    'only separators' => [' - - '],
]);

test('every accepted spelling hashes alike, to the SHA-256 of the normalised code', function () {
    $hashes = array_map(fn (string $typed): string => RedeemCode::tryParse($typed)->hash(), ['7KQ2-MX4D-9TRB-H3WE', '7kq2mx4d9trbh3we', ' 7kq2 mx4d 9trb h3we']);

    expect(array_unique($hashes))->toHaveCount(1)
        ->and($hashes[0])->toBe(hash('sha256', '7KQ2MX4D9TRBH3WE'))->toMatch('/^[0-9a-f]{64}$/');
});

test('the hash of a code does not contain the code', function () {
    $code = RedeemCode::generate();

    expect($code->hash())->not->toContain($code->value);
});
