<?php

/*
| Recovery code format and input tolerance (spec 018, R-11).
*/

use App\Modules\Admin\Domain\SecondFactor\RecoveryCode;

it('generates ten characters from the Crockford-style alphabet, shown as XXXXX-XXXXX', function () {
    foreach (range(1, 50) as $ignored) {
        $code = RecoveryCode::generate();

        expect($code->value)->toMatch('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{10}$/')
            ->and($code->display())->toMatch('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/')
            ->and(str_replace('-', '', $code->display()))->toBe($code->value);
    }
});

it('normalises case, spaces, hyphens and look-alike characters', function () {
    expect(RecoveryCode::normalise('abcde-fghjk'))->toBe('ABCDEFGHJK')
        ->and(RecoveryCode::normalise(' abcde fghjk '))->toBe('ABCDEFGHJK')
        ->and(RecoveryCode::normalise('ILO12-ILO12'))->toBe('1101211012')
        ->and(RecoveryCode::normalise('0123456789'))->toBe('0123456789');
});

it('refuses any other length or character', function (string $input) {
    expect(RecoveryCode::normalise($input))->toBeNull();
})->with(['short' => 'ABCDE-FGHJ', 'long' => 'ABCDE-FGHJKM', 'u' => 'ABCDE-FGHJU', 'symbol' => 'ABCDE-FGHJ!', 'empty' => '']);
