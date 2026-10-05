<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Export\ExportFailure;
use App\Modules\Identity\Domain\Export\ExportRecord;
use App\Modules\Identity\Domain\Export\ExportScope;
use DateTimeImmutable;

/**
 * Exports as Identity stores them (data-model: data_exports). At most one
 * per person is queued or running; a second insert violates the partial
 * unique index and surfaces as a unique-constraint exception.
 */
interface ExportRepository
{
    public function insertQueued(string $id, string $userId, ExportScope $scope, ?string $walletId, DateTimeImmutable $requestedAt): ExportRecord;

    public function find(string $id): ?ExportRecord;

    /**
     * The person's own export; null for another person's or an unknown id.
     */
    public function findFor(string $userId, string $id): ?ExportRecord;

    /**
     * The row, taking the lock that serialises its state changes.
     */
    public function findForUpdate(string $id): ?ExportRecord;

    /**
     * The person's queued or running export, if any.
     */
    public function activeFor(string $userId): ?ExportRecord;

    /**
     * The person's exports, newest first, one page plus one.
     *
     * @param  array{value: string, id: string}|null  $after  the last row of the previous page
     * @return list<ExportRecord>
     */
    public function listFor(string $userId, ?array $after, int $limit): array;

    /**
     * queued or running → running, started now. Anything else is left alone.
     *
     * @return bool whether the export is now running under this call
     */
    public function markRunning(string $id, DateTimeImmutable $at): bool;

    /**
     * running → ready. Anything else is left alone.
     */
    public function markReady(string $id, string $filePath, int $sizeBytes, DateTimeImmutable $completedAt, DateTimeImmutable $expiresAt): bool;

    /**
     * queued or running → failed. Anything else is left alone.
     */
    public function markFailed(string $id, ExportFailure $failure, DateTimeImmutable $at): bool;

    /**
     * Sets `delivered_at` only the first time.
     *
     * @return bool whether this call was the first delivery
     */
    public function markDelivered(string $id, DateTimeImmutable $at): bool;

    /**
     * ready → expired for every export past its expiry.
     *
     * @return list<ExportRecord> the exports expired by this call, as they were
     */
    public function expireReadyBefore(DateTimeImmutable $now): array;

    /**
     * queued or running since before the cut → failed (timed_out).
     *
     * @return list<ExportRecord> the exports failed by this call
     */
    public function failStaleBefore(DateTimeImmutable $cut, DateTimeImmutable $at): array;

    /**
     * Deletes the rows requested before the cut.
     *
     * @return list<string> the file paths they held
     */
    public function deleteRequestedBefore(DateTimeImmutable $cut): array;

    /**
     * Every export of the person's, for deleting its files.
     *
     * @return list<ExportRecord>
     */
    public function allFor(string $userId): array;

    public function deleteAllFor(string $userId): int;

    /**
     * Every file path some row still holds.
     *
     * @return list<string>
     */
    public function heldFilePaths(): array;
}
