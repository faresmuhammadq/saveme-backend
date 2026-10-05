<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Identity\Domain\Export\ExportRecord;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * An export is already queued or running for this person (spec 022, FR-017).
 * The active export travels with the refusal so the client shows it as
 * `current` without a second request.
 */
final class ExportInProgress extends Refusal
{
    /**
     * @param  array<string, mixed>|null  $current  the active export as a read renders it, when known
     */
    public function __construct(public readonly ExportRecord $active, ?array $current = null)
    {
        parent::__construct(ErrorCode::ExportInProgress, extensions: $current === null ? [] : ['current' => $current]);
    }
}
