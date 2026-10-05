<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\Export\ExportView;

/**
 * The `Export` schema (docs/openapi/paths/exports.yaml): every member, nulls
 * explicit.
 */
final class ExportResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ExportView $view): array
    {
        $export = $view->export;

        return [
            'id' => $export->id,
            'scope' => $export->scope->value,
            'wallet_id' => $export->walletId,
            'status' => $export->status->value,
            'failure_code' => $export->failure?->value,
            'requested_at' => Instants::format($export->requestedAt),
            'completed_at' => Instants::format($export->completedAt),
            'expires_at' => Instants::format($export->expiresAt),
            'size_bytes' => $export->sizeBytes,
            'download_url' => $view->downloadUrl,
            'download_url_expires_at' => Instants::format($view->downloadUrlExpiresAt),
        ];
    }
}
