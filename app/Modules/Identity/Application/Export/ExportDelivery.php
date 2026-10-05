<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\ExportDeliveredDetail;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Export\ExportStatus;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Ports\ExportStore;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\ExportNotFound;
use Illuminate\Support\Facades\DB;

/**
 * Serves an archive to whoever holds a valid signed link (spec 022, FR-014).
 * Not found for an unknown export, one that is not ready or is past its
 * expiry, a missing file, or an account no longer `active` or
 * `pending_deletion`. The first successful delivery writes one
 * `export_delivered` entry.
 */
final class ExportDelivery
{
    public function __construct(
        private readonly ExportRepository $exports,
        private readonly ExportStore $store,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return string the archive's absolute path
     *
     * @throws ExportNotFound
     */
    public function open(string $exportId): string
    {
        $export = $this->exports->find($exportId) ?? throw new ExportNotFound;
        $state = $this->users->statesOf([$export->userId])[$export->userId] ?? null;
        $now = now()->toImmutable();

        if ($export->status !== ExportStatus::Ready
            || $export->expiresAt === null || $export->expiresAt <= $now
            || $export->filePath === null
            || ($state !== AccountState::Active && $state !== AccountState::PendingDeletion)) {
            throw new ExportNotFound;
        }

        $path = $this->store->absolutePath($export->filePath) ?? throw new ExportNotFound;

        DB::transaction(function () use ($export, $now): void {
            if ($this->exports->markDelivered($export->id, $now)) {
                $this->audit->record(AuditRecord::byUser(AuditEvent::ExportDelivered, $export->userId, SubjectType::User, $export->userId, new ExportDeliveredDetail($export->id)));
            }
        });

        return $path;
    }
}
