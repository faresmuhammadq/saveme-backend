<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Ports\ExportStore;

/**
 * The export housekeeping run (spec 022, FR-016, FR-019): ready exports past
 * their expiry lose their file and become `expired`; exports queued or
 * running for too long fail as `timed_out`; records older than 30 days are
 * deleted with any file; files no row refers to are deleted. Idempotent.
 */
final class ExpireExports
{
    public function __construct(
        private readonly ExportRepository $exports,
        private readonly ExportStore $store,
    ) {}

    /**
     * @return array{expired: int, timed_out: int, deleted: int, orphans: int}
     */
    public function run(): array
    {
        $now = now()->toImmutable();

        $expired = $this->exports->expireReadyBefore($now);
        foreach ($expired as $export) {
            $this->store->delete($export->userId, $export->id);
        }

        $minutes = (int) config('identity.export_stale_minutes');
        $stale = $this->exports->failStaleBefore($now->modify("-{$minutes} minutes"), $now);
        foreach ($stale as $export) {
            $this->store->delete($export->userId, $export->id);
        }

        $days = (int) config('identity.export_record_days');
        $paths = $this->exports->deleteRequestedBefore($now->modify("-{$days} days"));
        foreach ($paths as $path) {
            $this->store->deleteFile($path);
        }

        $held = array_flip($this->exports->heldFilePaths());
        $orphans = 0;
        foreach ($this->store->allFiles() as $file) {
            $final = str_ends_with($file, '.part') ? substr($file, 0, -5) : $file;

            if (! isset($held[$final]) && ! $this->isBeingBuilt($file)) {
                $this->store->deleteFile($file);
                $orphans++;
            }
        }

        return ['expired' => count($expired), 'timed_out' => count($stale), 'deleted' => count($paths), 'orphans' => $orphans];
    }

    /**
     * A `.part` file of an export still queued or running is work in progress.
     */
    private function isBeingBuilt(string $file): bool
    {
        if (! str_ends_with($file, '.part')) {
            return false;
        }

        $id = basename(substr($file, 0, -9));
        $export = $this->exports->find($id);

        return $export !== null && $export->status->isActive();
    }
}
