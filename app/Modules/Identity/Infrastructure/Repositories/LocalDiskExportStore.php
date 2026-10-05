<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\ExportStore;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Export archives on the private `local` disk (storage/app/private/exports),
 * `exports/<user_id>/<export_id>.zip`, built under `.part` and renamed.
 */
final class LocalDiskExportStore implements ExportStore
{
    public function pathFor(string $userId, string $exportId): string
    {
        return "exports/{$userId}/{$exportId}.zip";
    }

    public function partFile(string $userId, string $exportId): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory("exports/{$userId}");

        return $disk->path($this->pathFor($userId, $exportId).'.part');
    }

    public function commit(string $userId, string $exportId): int
    {
        $disk = Storage::disk('local');
        $final = $this->pathFor($userId, $exportId);

        if (! rename($disk->path($final.'.part'), $disk->path($final))) {
            throw new RuntimeException('The export archive could not be finished.');
        }

        return (int) $disk->size($final);
    }

    public function absolutePath(string $relativePath): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists($relativePath) ? $disk->path($relativePath) : null;
    }

    public function delete(string $userId, string $exportId): void
    {
        $final = $this->pathFor($userId, $exportId);
        Storage::disk('local')->delete([$final, $final.'.part']);
    }

    public function deleteAllFor(string $userId): void
    {
        Storage::disk('local')->deleteDirectory("exports/{$userId}");
    }

    public function allFiles(): array
    {
        return array_values(Storage::disk('local')->allFiles('exports'));
    }

    public function deleteFile(string $relativePath): void
    {
        Storage::disk('local')->delete($relativePath);
    }
}
