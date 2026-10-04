<?php

namespace App\Modules\Billing\Domain\RedeemCodes;

/**
 * The secret an approved claim issues (spec 020, FR-022, FR-023, R-7): 16
 * characters of Crockford's base32 (80 bits), shown as four groups of four.
 * Input is read loosely (any case, hyphens and spaces ignored, `O` read as
 * `0`, `I` and `L` as `1`); `U` is never issued. Only the SHA-256 of the
 * normalised form is ever stored.
 */
final readonly class RedeemCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 16;

    private function __construct(public string $value) {}

    public static function generate(): self
    {
        $bits = '';

        foreach (str_split(random_bytes(10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $code = '';

        foreach (str_split($bits, 5) as $group) {
            $code .= substr(self::ALPHABET, (int) bindec($group), 1);
        }

        return new self($code);
    }

    /**
     * The code a person typed, or null when it is not 16 code characters.
     */
    public static function tryParse(string $raw): ?self
    {
        $folded = strtr(strtoupper(str_replace([' ', '-'], '', $raw)), ['O' => '0', 'I' => '1', 'L' => '1']);

        return preg_match('/^[0-9A-HJKMNP-TV-Z]{'.self::LENGTH.'}$/', $folded) === 1 ? new self($folded) : null;
    }

    /**
     * `XXXX-XXXX-XXXX-XXXX`.
     */
    public function display(): string
    {
        return implode('-', str_split($this->value, 4));
    }

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
