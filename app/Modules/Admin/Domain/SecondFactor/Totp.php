<?php

namespace App\Modules\Admin\Domain\SecondFactor;

/**
 * Time-based one-time codes: RFC 6238 over RFC 4226 with HMAC-SHA1, six
 * digits, 30-second steps (spec 018, R-1, R-3; contracts/second-factor.md).
 */
final class Totp
{
    public const STEP_SECONDS = 30;

    public const DIGITS = 6;

    public static function stepAt(int $unixSeconds): int
    {
        return intdiv($unixSeconds, self::STEP_SECONDS);
    }

    public static function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step the input matches within the drift window, if that step is
     * later than the last accepted one. Every candidate is compared, with no
     * early exit.
     */
    public static function verify(string $secret, string $input, int $unixSeconds, ?int $lastStep, int $driftSteps = 1): ?int
    {
        $normalised = str_replace(' ', '', $input);

        if (preg_match('/^\d{6}$/', $normalised) !== 1) {
            return null;
        }

        $current = self::stepAt($unixSeconds);
        $matched = null;

        for ($step = $current - $driftSteps; $step <= $current + $driftSteps; $step++) {
            if (hash_equals(self::codeAt($secret, $step), $normalised)) {
                $matched = $step;
            }
        }

        if ($matched === null || ($lastStep !== null && $matched <= $lastStep)) {
            return null;
        }

        return $matched;
    }
}
