<?php

namespace App\Modules\Identity\Domain\Preferences;

use DateTimeImmutable;

/**
 * A Profile as the application sees it, with the display name opened. The
 * five writable preferences plus the sync members (spec 008).
 */
final readonly class ProfileRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $displayName,
        public string $locale,
        public string $timezone,
        public string $defaultCurrency,
        public int $budgetPeriodStartDay,
        public ?string $budgetPeriodStartDayFrom,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @return array{display_name: ?string, locale: string, timezone: string, default_currency: string, budget_period_start_day: int}
     */
    public function writableValues(): array
    {
        return [
            'display_name' => $this->displayName,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'default_currency' => $this->defaultCurrency,
            'budget_period_start_day' => $this->budgetPeriodStartDay,
        ];
    }
}
