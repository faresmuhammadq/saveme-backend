<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Domain\Registration\PushRegistrationRecord;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A Push Registration as the contract frames it: the session, when it first
 * registered, when it last refreshed. Never the token, sealed or not.
 */
final class PushRegistrationResource
{
    /**
     * @return array{session_id: string, registered_at: string, refreshed_at: string}
     */
    public static function toArray(PushRegistrationRecord $registration): array
    {
        return [
            'session_id' => $registration->sessionId,
            'registered_at' => self::instant($registration->registeredAt),
            'refreshed_at' => self::instant($registration->refreshedAt),
        ];
    }

    public static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
