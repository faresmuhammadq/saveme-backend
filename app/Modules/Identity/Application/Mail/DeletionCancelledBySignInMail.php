<?php

namespace App\Modules\Identity\Application\Mail;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A sign-in during the grace period cancelled the deletion (FR-054). Names
 * the device and the time, and advises a reset if it was not the owner.
 */
final class DeletionCancelledBySignInMail extends IdentityMail
{
    public function __construct(
        public readonly string $deviceName,
        public readonly DateTimeImmutable $signedInAt,
        public readonly string $timezone,
    ) {}

    protected function key(): string
    {
        return 'deletion_cancelled_by_sign_in';
    }

    protected function viewData(): array
    {
        return [
            'device' => $this->deviceName,
            'time' => $this->signedInAt->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i T'),
        ];
    }
}
