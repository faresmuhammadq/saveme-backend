<?php

namespace App\Modules\Admin\Domain\SecondFactor;

/**
 * A single-use recovery code: ten characters from a Crockford-style alphabet
 * (no I, L, O, U), shown as XXXXX-XXXXX, 50 bits of entropy (spec 018, R-11).
 */
final readonly class RecoveryCode
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 10;

    private function __construct(public string $value) {}

    public static function generate(): self
    {
        $code = '';

        for ($index = 0; $index < self::LENGTH; $index++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return new self($code);
    }

    public function display(): string
    {
        return substr($this->value, 0, 5).'-'.substr($this->value, 5);
    }

    /**
     * What an operator typed, as the stored form, or null when it cannot be a code.
     */
    public static function normalise(string $input): ?string
    {
        $code = strtr(strtoupper(str_replace([' ', '-'], '', $input)), ['I' => '1', 'L' => '1', 'O' => '0']);

        if (preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }
}
