<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Jobs\SendExportReadyMail;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Events\ExportReady;
use App\Modules\Identity\Domain\Export\ExportFailure;
use App\Modules\Identity\Domain\Export\ExportRecord;
use App\Modules\Identity\Domain\Export\ExportStatus;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Ports\ExportStore;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Produces an export's archive (spec 022, FR-011, R-5). Safe to run again:
 * a retry rebuilds from scratch under the same name; a finished export is
 * left alone. Each module's section writes through the writer as the
 * person; the archive is built under a `.part` name and renamed. If the row
 * is gone or the account has left `active` / `pending_deletion` while it
 * worked, no file is kept. Nothing about the content is logged, ids only.
 */
final class GenerateExport
{
    /**
     * @param  iterable<ExportSection>  $sections
     */
    public function __construct(
        private readonly ExportRepository $exports,
        private readonly ExportStore $store,
        private readonly UserRepository $users,
        private readonly iterable $sections = [],
    ) {}

    /**
     * @throws WalletUnavailable when a wallet export's wallet is no longer readable
     */
    public function run(string $exportId): void
    {
        $export = $this->exports->find($exportId);

        if ($export === null || ! $export->status->isActive()) {
            return;
        }

        if (! $this->accountAllows($export)) {
            $this->store->delete($export->userId, $export->id);

            return;
        }

        $this->exports->markRunning($export->id, now()->toImmutable());
        Log::info('export_started', ['export_id' => $export->id]);

        $writer = new ZipExportWriter;
        $target = new ExportTarget($export->userId, $export->scope, $export->walletId);
        $part = $this->store->partFile($export->userId, $export->id);

        try {
            foreach ($this->sections as $section) {
                $section->write($target, $writer);
            }

            $writer->finish($part, $target);
        } catch (Throwable $failure) {
            $writer->cleanUp();
            $this->store->delete($export->userId, $export->id);

            throw $failure;
        }

        $this->finish($export);
    }

    /**
     * Marks a run that will not be retried as failed, and removes its files.
     */
    public function fail(string $exportId, ExportFailure $failure): void
    {
        $export = $this->exports->find($exportId);

        if ($export === null) {
            return;
        }

        $this->store->delete($export->userId, $export->id);
        $this->exports->markFailed($export->id, $failure, now()->toImmutable());
        Log::info('export_failed', ['export_id' => $exportId, 'failure' => $failure->value]);
    }

    private function finish(ExportRecord $export): void
    {
        $ready = DB::transaction(function () use ($export): bool {
            $current = $this->exports->findForUpdate($export->id);

            if ($current === null || $current->status !== ExportStatus::Running || ! $this->accountAllows($current)) {
                return false;
            }

            $size = $this->store->commit($export->userId, $export->id);
            $completed = now()->toImmutable();
            $hours = (int) config('identity.export_hours');

            return $this->exports->markReady($export->id, $this->store->pathFor($export->userId, $export->id), $size, $completed, $completed->modify("+{$hours} hours"));
        });

        if (! $ready) {
            $this->store->delete($export->userId, $export->id);

            return;
        }

        Log::info('export_ready', ['export_id' => $export->id]);
        SendExportReadyMail::dispatch($export->id)->afterCommit();
        event(new ExportReady(...Events::stamp(), userId: $export->userId, exportId: $export->id));
    }

    private function accountAllows(ExportRecord $export): bool
    {
        $state = $this->users->statesOf([$export->userId])[$export->userId] ?? null;

        return $state === AccountState::Active || $state === AccountState::PendingDeletion;
    }
}
