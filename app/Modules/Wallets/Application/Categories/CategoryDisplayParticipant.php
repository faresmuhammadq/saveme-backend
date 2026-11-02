<?php

namespace App\Modules\Wallets\Application\Categories;

/**
 * A module that keeps a copy of a person's category display fields beside its
 * own records (Budgeting: a Shared Budget line, so every member can render a
 * category they do not own). Told in the same unit of work as the rename, with
 * the category row already locked by the caller (ADR-0027 rule 10); tagged
 * with this interface's name.
 */
interface CategoryDisplayParticipant
{
    public function republish(string $categoryId, string $name, ?string $icon, ?string $color): void;
}
