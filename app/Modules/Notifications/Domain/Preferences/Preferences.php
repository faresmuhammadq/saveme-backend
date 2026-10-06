<?php

namespace App\Modules\Notifications\Domain\Preferences;

use DateTimeImmutable;

/**
 * One person's Notification Preferences (spec 023): six category switches and
 * quiet hours, versioned, never synced. A person who never saved has the
 * defaults at version 1.
 */
final readonly class Preferences
{
    /**
     * @param  array<string, bool>  $categories  keyed by Category value, all six present
     */
    public function __construct(
        public int $version,
        public array $categories,
        public QuietHours $quietHours,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @param  array{enabled: bool, start: int, end: int}  $quietHours  minutes since midnight
     */
    public static function defaults(array $quietHours): self
    {
        return new self(
            1,
            array_fill_keys(array_map(fn (Category $category): string => $category->value, Category::cases()), true),
            new QuietHours($quietHours['enabled'], $quietHours['start'], $quietHours['end']),
            null,
        );
    }

    public function allows(Category $category): bool
    {
        return $this->categories[$category->value] ?? true;
    }

    /**
     * Whether another set of choices says the same thing, version and time aside.
     */
    public function sameChoicesAs(self $other): bool
    {
        return $this->categories == $other->categories && $this->quietHours == $other->quietHours;
    }

    public function withChoices(self $choices, int $version, DateTimeImmutable $updatedAt): self
    {
        return new self($version, $choices->categories, $choices->quietHours, $updatedAt);
    }
}
