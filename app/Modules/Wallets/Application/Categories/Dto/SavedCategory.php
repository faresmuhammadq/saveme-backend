<?php

namespace App\Modules\Wallets\Application\Categories\Dto;

/**
 * The outcome of a category save: the category as a read presents it, and
 * whether this call created it (201) or not (200).
 */
final readonly class SavedCategory
{
    public function __construct(
        public CategoryView $view,
        public bool $created,
    ) {}
}
