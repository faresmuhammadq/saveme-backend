<?php

namespace App\Modules\Admin\Http\Presenters;

/**
 * Middle-truncates opaque identifiers for display; the full value is always
 * what gets copied (spec 006, FR-024).
 */
class IdentifierPresenter
{
    public function truncate(string $identifier, int $head = 6, int $tail = 6): string
    {
        if (mb_strlen($identifier) <= 16) {
            return $identifier;
        }

        return mb_substr($identifier, 0, $head).'…'.mb_substr($identifier, -$tail);
    }
}
