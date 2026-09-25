<?php

namespace App\Modules\Admin\Http\Navigation;

use App\Modules\Admin\Http\Icons\IconRegistry;
use Throwable;

/**
 * The back-office sidebar, assembled from what features register (spec 006,
 * FR-007, FR-008, FR-010; contracts/shell-and-navigation.md).
 *
 * A feature adds its entries from its own service provider:
 *
 *     $registry->add(new NavigationEntry(key: 'claims', group: 'operate', …));
 */
class NavigationRegistry
{
    /** @var array<string, NavigationGroup> */
    private array $groups = [];

    /** @var array<string, NavigationEntry> */
    private array $entries = [];

    public function __construct(private readonly IconRegistry $icons)
    {
        $this->group('operate', 'admin::shell.groups.operate', 10);
        $this->group('configure', 'admin::shell.groups.configure', 20);
    }

    public function group(string $key, string $labelKey, int $order): void
    {
        $this->groups[$key] = new NavigationGroup($key, $labelKey, $order);
    }

    /**
     * @throws DuplicateNavigationEntry
     * @throws UnknownNavigationGroup
     */
    public function add(NavigationEntry $entry): void
    {
        if (isset($this->entries[$entry->key])) {
            throw DuplicateNavigationEntry::key($entry->key);
        }

        if (! isset($this->groups[$entry->group])) {
            throw UnknownNavigationGroup::key($entry->group, $entry->key);
        }

        $this->icons->get($entry->icon);

        $this->entries[$entry->key] = $entry;
    }

    /**
     * Groups in order, each with its entries in order, the current entry
     * resolved and badges evaluated. Groups without entries are left out.
     *
     * @return list<array{group: NavigationGroup, entries: list<array{entry: NavigationEntry, current: bool, badge: ?int}>}>
     */
    public function groups(?string $currentRoute = null): array
    {
        $current = $this->currentKey($currentRoute);

        $groups = array_values($this->groups);
        usort($groups, fn (NavigationGroup $a, NavigationGroup $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        $resolved = [];

        foreach ($groups as $group) {
            $entries = array_values(array_filter($this->entries, fn (NavigationEntry $entry): bool => $entry->group === $group->key));
            usort($entries, fn (NavigationEntry $a, NavigationEntry $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

            if ($entries === []) {
                continue;
            }

            $resolved[] = [
                'group' => $group,
                'entries' => array_map(fn (NavigationEntry $entry): array => [
                    'entry' => $entry,
                    'current' => $entry->key === $current,
                    'badge' => $this->badge($entry),
                ], $entries),
            ];
        }

        return $resolved;
    }

    /**
     * The entry whose route matches the current one; the longest match wins.
     */
    private function currentKey(?string $currentRoute): ?string
    {
        if ($currentRoute === null) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach ($this->entries as $entry) {
            $matches = $currentRoute === $entry->route || str_starts_with($currentRoute, $entry->route.'.');

            if ($matches && strlen($entry->route) > $bestLength) {
                $best = $entry->key;
                $bestLength = strlen($entry->route);
            }
        }

        return $best;
    }

    /**
     * A failing count never breaks the shell: it is reported and hidden.
     */
    private function badge(NavigationEntry $entry): ?int
    {
        if ($entry->badge === null) {
            return null;
        }

        try {
            $count = ($entry->badge)();
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }

        return $count === null || $count <= 0 ? null : $count;
    }
}
