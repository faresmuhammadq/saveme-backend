<?php

namespace App\Modules\Admin\Domain\SecondFactor;

/**
 * RFC 4648 base32 (alphabet A–Z2–7) as authenticator apps expect it: encoded
 * without padding, decoded case-insensitively and ignoring spaces and `=`
 * (spec 018, R-2).
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $binary): string
    {
        $bits = '';

        foreach (str_split($binary) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    /**
     * @return string|null the bytes, or null when a character is outside the alphabet
     */
    public static function decode(string $encoded): ?string
    {
        $clean = strtoupper(str_replace([' ', '='], '', $encoded));
        $bits = '';

        foreach (str_split($clean) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                return null;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk) & 0xFF);
            }
        }

        return $bytes;
    }
}
