<?php

namespace App\Modules\Billing\Domain\Ports;

/**
 * Authenticated encryption of a purchase token, bound to the row that holds
 * it (spec 024, R-4): `$subject` is `play_purchase` or `play_notification`.
 */
interface PlayTokenSealer
{
    public function seal(#[\SensitiveParameter] string $token, string $subject, string $id): string;

    public function open(string $sealed, string $subject, string $id): string;
}
