<?php

namespace App\Modules\Identity\Application\Mail;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The account will be deleted on a date; signing in or cancelling in the app
 * keeps it. The date is shown in the person's timezone, Western numerals.
 */
final class DeletionScheduledMail extends IdentityMail
{
    public function __construct(
        public readonly DateTimeImmutable $scheduledFor,
        public readonly string $timezone,
    ) {}

    protected function key(): string
    {
        return 'deletion_scheduled';
    }

    protected function viewData(): array
    {
        return ['date' => $this->scheduledFor->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d')];
    }
}
