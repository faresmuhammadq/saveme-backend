<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets;

use DateTimeImmutable;

/**
 * A Shared Budget as stored (ADR-0027). `changeSeq` is also the scope's counter.
 * The histories ride the record on the wire, so a change to either re-stamps it:
 * each limit applies from a Budget Period start, each start day from a date
 * (null for the first).
 *
 * @phpstan-type LimitHistory list<array{applies_from: string, limit_minor: ?int}>
 * @phpstan-type StartDayHistory list<array{start_day: int, applies_from: ?string}>
 */
final readonly class SharedBudgetRecord
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $name,
        public string $currency,
        public int $startDay,
        public string $timezone,
        public ?int $limitMinor,
        public ?DateTimeImmutable $archivedAt,
        public int $version,
        public int $changeSeq,
        public int $purgedThrough,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
        /** @var LimitHistory */
        public array $limitHistory = [],
        /** @var StartDayHistory */
        public array $startDayHistory = [],
    ) {}

    public function isTombstoned(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /**
     * The members a whole save writes.
     *
     * @return array{name: string, currency: string, start_day: int, limit_minor: ?int}
     */
    public function writableValues(): array
    {
        return [
            'name' => $this->name,
            'currency' => $this->currency,
            'start_day' => $this->startDay,
            'limit_minor' => $this->limitMinor,
        ];
    }
}
