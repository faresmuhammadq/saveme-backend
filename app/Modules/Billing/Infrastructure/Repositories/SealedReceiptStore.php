<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Ports\ReceiptStore;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Receipt bytes on the application's private `local` disk (storage/app/private),
 * at `billing/claims/{claim id}/receipt`: no extension, no client-supplied name
 * (spec 020, R-5). The bytes arrive sealed.
 */
final class SealedReceiptStore implements ReceiptStore
{
    public function put(string $claimId, string $sealedBytes): string
    {
        $path = "billing/claims/{$claimId}/receipt";

        if (! Storage::disk('local')->put($path, $sealedBytes)) {
            throw new RuntimeException('The receipt could not be stored.');
        }

        return $path;
    }

    public function get(string $path): ?string
    {
        $bytes = Storage::disk('local')->get($path);

        return is_string($bytes) ? $bytes : null;
    }

    public function delete(string $path): void
    {
        Storage::disk('local')->deleteDirectory(dirname($path));
    }
}
