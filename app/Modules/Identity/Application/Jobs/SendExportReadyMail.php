<?php

namespace App\Modules\Identity\Application\Jobs;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Export\ExportQuery;
use App\Modules\Identity\Application\Mail\ExportReadyMail;
use App\Modules\Identity\Domain\Export\ExportStatus;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the person their export is ready, with a link that works until the
 * archive expires (spec 022, FR-015). The link is signed here, at send time,
 * so the payload is only an id; the job is encrypted at rest all the same,
 * as the mail's recipient and link are secrets. Nothing is logged.
 */
final class SendExportReadyMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $exportId) {}

    public function handle(ExportRepository $exports, Accounts $accounts, ProfileRepository $profiles): void
    {
        $export = $exports->find($this->exportId);

        if ($export === null || $export->status !== ExportStatus::Ready || $export->expiresAt === null || $export->expiresAt <= now()) {
            return;
        }

        Mail::to($accounts->address($export->userId))
            ->locale($profiles->localeOf($export->userId))
            ->send(new ExportReadyMail(
                ExportQuery::linkFor($export->id, $export->expiresAt),
                (int) $export->sizeBytes,
                $export->expiresAt,
                $profiles->timezoneOf($export->userId),
            ));
    }
}
