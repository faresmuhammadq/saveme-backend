<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Export\ExportFailure;
use App\Modules\Identity\Domain\Export\ExportRecord;
use App\Modules\Identity\Domain\Export\ExportScope;
use App\Modules\Identity\Domain\Export\ExportStatus;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use DateTimeImmutable;

final class EloquentExportRepository implements ExportRepository
{
    /** Instants keep microseconds; the query builder would drop them. */
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function insertQueued(string $id, string $userId, ExportScope $scope, ?string $walletId, DateTimeImmutable $requestedAt): ExportRecord
    {
        DataExport::query()->forceCreate([
            'id' => $id,
            'user_id' => $userId,
            'scope' => $scope->value,
            'wallet_id' => $walletId,
            'status' => ExportStatus::Queued->value,
            'requested_at' => $requestedAt->format(self::INSTANT),
        ]);

        return $this->record(DataExport::query()->findOrFail($id));
    }

    public function find(string $id): ?ExportRecord
    {
        $row = DataExport::query()->find($id);

        return $row === null ? null : $this->record($row);
    }

    public function findFor(string $userId, string $id): ?ExportRecord
    {
        $row = DataExport::query()->where('user_id', $userId)->whereKey($id)->first();

        return $row === null ? null : $this->record($row);
    }

    public function findForUpdate(string $id): ?ExportRecord
    {
        $row = DataExport::query()->whereKey($id)->lockForUpdate()->first();

        return $row === null ? null : $this->record($row);
    }

    public function activeFor(string $userId): ?ExportRecord
    {
        $row = DataExport::query()->where('user_id', $userId)->whereIn('status', ['queued', 'running'])->first();

        return $row === null ? null : $this->record($row);
    }

    public function listFor(string $userId, ?array $after, int $limit): array
    {
        $query = DataExport::query()->where('user_id', $userId);

        if ($after !== null) {
            $query->whereRaw('(requested_at, id) < (?::timestamptz, ?::uuid)', [$after['value'], $after['id']]);
        }

        return array_values($query->orderByDesc('requested_at')->orderByDesc('id')->limit($limit + 1)->get()
            ->map(fn (DataExport $row): ExportRecord => $this->record($row))->all());
    }

    public function markRunning(string $id, DateTimeImmutable $at): bool
    {
        return DataExport::query()->whereKey($id)->whereIn('status', ['queued', 'running'])->toBase()->update([
            'status' => ExportStatus::Running->value,
            'started_at' => $at->format(self::INSTANT),
            'updated_at' => $at->format(self::INSTANT),
        ]) === 1;
    }

    public function markReady(string $id, string $filePath, int $sizeBytes, DateTimeImmutable $completedAt, DateTimeImmutable $expiresAt): bool
    {
        return DataExport::query()->whereKey($id)->where('status', 'running')->toBase()->update([
            'status' => ExportStatus::Ready->value,
            'file_path' => $filePath,
            'size_bytes' => $sizeBytes,
            'completed_at' => $completedAt->format(self::INSTANT),
            'expires_at' => $expiresAt->format(self::INSTANT),
            'updated_at' => $completedAt->format(self::INSTANT),
        ]) === 1;
    }

    public function markFailed(string $id, ExportFailure $failure, DateTimeImmutable $at): bool
    {
        return DataExport::query()->whereKey($id)->whereIn('status', ['queued', 'running'])->toBase()->update([
            'status' => ExportStatus::Failed->value,
            'failure_code' => $failure->value,
            'completed_at' => $at->format(self::INSTANT),
            'updated_at' => $at->format(self::INSTANT),
        ]) === 1;
    }

    public function markDelivered(string $id, DateTimeImmutable $at): bool
    {
        return DataExport::query()->whereKey($id)->whereNull('delivered_at')->toBase()->update([
            'delivered_at' => $at->format(self::INSTANT),
            'updated_at' => $at->format(self::INSTANT),
        ]) === 1;
    }

    public function expireReadyBefore(DateTimeImmutable $now): array
    {
        $rows = DataExport::query()->where('status', 'ready')->where('expires_at', '<=', $now->format(self::INSTANT))->get();
        $expired = [];

        foreach ($rows as $row) {
            $changed = DataExport::query()->whereKey($row->id)->where('status', 'ready')->toBase()->update([
                'status' => ExportStatus::Expired->value,
                'file_path' => null,
                'updated_at' => $now->format(self::INSTANT),
            ]);

            if ($changed === 1) {
                $expired[] = $this->record($row);
            }
        }

        return $expired;
    }

    public function failStaleBefore(DateTimeImmutable $cut, DateTimeImmutable $at): array
    {
        $rows = DataExport::query()->whereIn('status', ['queued', 'running'])->where('requested_at', '<', $cut->format(self::INSTANT))->get();
        $failed = [];

        foreach ($rows as $row) {
            if ($this->markFailed($row->id, ExportFailure::TimedOut, $at)) {
                $failed[] = $this->record($row);
            }
        }

        return $failed;
    }

    public function deleteRequestedBefore(DateTimeImmutable $cut): array
    {
        $rows = DataExport::query()->where('requested_at', '<', $cut->format(self::INSTANT))->whereNotIn('status', ['queued', 'running'])->get();
        $paths = [];

        foreach ($rows as $row) {
            if ($row->file_path !== null) {
                $paths[] = $row->file_path;
            }
        }

        DataExport::query()->whereKey($rows->modelKeys())->delete();

        return $paths;
    }

    public function allFor(string $userId): array
    {
        return array_values(DataExport::query()->where('user_id', $userId)->get()->map(fn (DataExport $row): ExportRecord => $this->record($row))->all());
    }

    public function deleteAllFor(string $userId): int
    {
        return DataExport::query()->where('user_id', $userId)->delete();
    }

    public function heldFilePaths(): array
    {
        return array_values(array_map('strval', DataExport::query()->whereNotNull('file_path')->pluck('file_path')->all()));
    }

    private function record(DataExport $row): ExportRecord
    {
        return new ExportRecord(
            id: $row->id,
            userId: $row->user_id,
            scope: ExportScope::from($row->scope),
            walletId: $row->wallet_id,
            status: ExportStatus::from($row->status),
            failure: $row->failure_code === null ? null : ExportFailure::from($row->failure_code),
            filePath: $row->file_path,
            sizeBytes: $row->size_bytes,
            requestedAt: $row->requested_at->toDateTimeImmutable(),
            startedAt: $row->started_at?->toDateTimeImmutable(),
            completedAt: $row->completed_at?->toDateTimeImmutable(),
            expiresAt: $row->expires_at?->toDateTimeImmutable(),
            deliveredAt: $row->delivered_at?->toDateTimeImmutable(),
        );
    }
}
