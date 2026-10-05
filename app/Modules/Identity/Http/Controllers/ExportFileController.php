<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Export\ExportDelivery;
use App\Modules\Identity\Domain\Refusals\ExportNotFound;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves an export archive to whoever holds a valid signed link (spec 022,
 * FR-014, R-6). Not an API operation: a browser or share sheet opens it
 * without bearer or client headers. A bad or expired signature, an unknown
 * or expired export, or a departed account all answer a plain 404.
 */
final class ExportFileController
{
    public function __invoke(Request $request, string $export, ExportDelivery $delivery): BinaryFileResponse
    {
        if (! $request->hasValidSignature()) {
            abort(404);
        }

        try {
            $path = $delivery->open($export);
        } catch (ExportNotFound) {
            abort(404);
        }

        return response()->download($path, 'export-'.now()->utc()->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
