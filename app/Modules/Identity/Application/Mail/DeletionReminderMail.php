<?php

namespace App\Modules\Identity\Application\Mail;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The account will be deleted in a few days (spec 022, FR-058): the date,
 * that signing in or cancelling stops it, the export suggestion and the
 * Google Play sentence. Names no wallet, amount or other person.
 */
final class DeletionReminderMail extends IdentityMail
{
    public function __construct(
        public readonly DateTimeImmutable $scheduledFor,
        public readonly string $timezone,
    ) {}

    protected function key(): string
    {
        return 'deletion_reminder';
    }

    protected function viewData(): array
    {
        return ['date' => $this->scheduledFor->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d')];
    }
}
