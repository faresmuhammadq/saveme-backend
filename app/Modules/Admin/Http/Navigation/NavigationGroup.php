<?php

namespace App\Modules\Admin\Http\Navigation;

/**
 * A heading in the back-office sidebar (spec 006, FR-007).
 */
final readonly class NavigationGroup
{
    /**
     * @param  string  $key  unique, kebab-case (operate, configure)
     * @param  string  $labelKey  translation key present in ar and en
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public int $order,
    ) {}
}
