<?php

namespace App\Modules\Admin\Http\Navigation;

use Closure;

/**
 * A sidebar item a feature registers without editing the shell (spec 006,
 * FR-007; data-model.md). No role or visibility field: every operator is an
 * admin in MVP.
 */
final readonly class NavigationEntry
{
    /**
     * @param  string  $key  unique across the registry
     * @param  string  $group  a declared group key
     * @param  string  $labelKey  translation key present in ar and en
     * @param  string  $icon  a name in IconRegistry
     * @param  string  $route  route name; current when the request's route equals it or starts with "{route}."
     * @param  (Closure(): ?int)|null  $badge  called at render; null or 0 hides the badge
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $labelKey,
        public string $icon,
        public string $route,
        public int $order = 0,
        public ?Closure $badge = null,
    ) {}
}
