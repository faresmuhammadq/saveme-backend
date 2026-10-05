<?php

namespace App\Modules\Identity\Application\Mail;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The export is ready. Carries a signed link that works until the archive
 * expires, the size and the expiry; never a wallet name (spec 022, FR-060).
 */
final class ExportReadyMail extends IdentityMail
{
    public function __construct(
        public readonly string $link,
        public readonly int $sizeBytes,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $timezone,
    ) {}

    protected function key(): string
    {
        return 'export_ready';
    }

    protected function viewData(): array
    {
        $megabytes = $this->sizeBytes >= 1048576;

        return [
            'link' => $this->link,
            'size' => $megabytes ? number_format($this->sizeBytes / 1048576, 1, '.', '').' MB' : max(1, (int) ceil($this->sizeBytes / 1024)).' KB',
            'expiry' => $this->expiresAt->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i'),
        ];
    }
}
