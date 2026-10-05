<?php

namespace App\Modules\Identity\Application\Jobs;

use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Application\Export\WalletUnavailable;
use App\Modules\Identity\Domain\Export\ExportFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Produces one export in the background (spec 022, R-5): at most 15 minutes
 * a run, three attempts, one run at a time per export. A wallet that is no
 * longer readable fails the export at once, without a retry.
 */
final class GenerateExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** Seconds a unique lock outlives a worker that died mid-run. */
    public int $uniqueFor = 1800;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public readonly string $exportId) {}

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    public function handle(GenerateExport $generate): void
    {
        try {
            $generate->run($this->exportId);
        } catch (WalletUnavailable) {
            $generate->fail($this->exportId, ExportFailure::WalletUnavailable);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerateExport::class)->fail($this->exportId, ExportFailure::GenerationFailed);
    }
}
