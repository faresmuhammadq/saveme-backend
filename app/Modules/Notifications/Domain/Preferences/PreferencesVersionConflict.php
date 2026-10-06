<?php

namespace App\Modules\Notifications\Domain\Preferences;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A preferences save carried a stale version. Nothing was applied and nothing
 * is merged; `current` carries the stored preferences (P-07 rules 2 to 3,
 * applied by choice, spec 023 FR-013).
 */
final class PreferencesVersionConflict extends Refusal
{
    /**
     * @param  array<string, mixed>  $current  the preferences as a read returns them
     */
    public function __construct(array $current)
    {
        parent::__construct(ErrorCode::VersionConflict, extensions: ['current' => $current]);
    }
}
