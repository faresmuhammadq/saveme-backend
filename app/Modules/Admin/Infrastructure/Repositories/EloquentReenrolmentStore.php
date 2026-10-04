<?php

namespace App\Modules\Admin\Infrastructure\Repositories;

use App\Modules\Admin\Domain\Operators\ReenrolmentRecord;
use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;
use App\Modules\Admin\Infrastructure\Models\OperatorReenrolmentApproval;
use App\Modules\Admin\Infrastructure\Models\OperatorReenrolmentRequest;
use DateTimeImmutable;

final class EloquentReenrolmentStore implements ReenrolmentStore
{
    public function findOpenFor(string $operatorId): ?ReenrolmentRecord
    {
        $row = OperatorReenrolmentRequest::query()
            ->where('operator_id', $operatorId)
            ->where('state', ReenrolmentState::Open->value)
            ->first();

        return $row === null ? null : $this->record($row);
    }

    public function open(string $operatorId, DateTimeImmutable $openedAt, DateTimeImmutable $expiresAt): ReenrolmentRecord
    {
        $row = OperatorReenrolmentRequest::query()->create([
            'operator_id' => $operatorId,
            'state' => ReenrolmentState::Open->value,
            'opened_at' => $openedAt,
            'expires_at' => $expiresAt,
        ]);

        return $this->record($row->refresh());
    }

    public function lockForUpdate(string $requestId): ?ReenrolmentRecord
    {
        $row = OperatorReenrolmentRequest::query()->whereKey($requestId)->lockForUpdate()->first();

        return $row === null ? null : $this->record($row);
    }

    public function addApproval(string $requestId, string $approverId, DateTimeImmutable $at): void
    {
        OperatorReenrolmentApproval::query()->create([
            'request_id' => $requestId,
            'approver_id' => $approverId,
            'approved_at' => $at,
        ]);
    }

    public function hasApproval(string $requestId, string $approverId): bool
    {
        return OperatorReenrolmentApproval::query()
            ->where('request_id', $requestId)
            ->where('approver_id', $approverId)
            ->exists();
    }

    public function approvalCount(string $requestId): int
    {
        return OperatorReenrolmentApproval::query()->where('request_id', $requestId)->count();
    }

    public function close(string $requestId, ReenrolmentState $state, ?string $closedBy, DateTimeImmutable $at): void
    {
        OperatorReenrolmentRequest::query()->findOrFail($requestId)->forceFill([
            'state' => $state->value,
            'closed_at' => $at,
            'closed_by' => $closedBy,
        ])->save();
    }

    public function listOpen(): array
    {
        return array_values(OperatorReenrolmentRequest::query()
            ->where('state', ReenrolmentState::Open->value)
            ->orderBy('opened_at')
            ->get()
            ->map(fn (OperatorReenrolmentRequest $row): ReenrolmentRecord => $this->record($row))
            ->all());
    }

    public function dueForExpiry(DateTimeImmutable $now): array
    {
        return array_values(OperatorReenrolmentRequest::query()
            ->where('state', ReenrolmentState::Open->value)
            ->where('expires_at', '<=', $now->format('Y-m-d H:i:s.uP'))
            ->orderBy('opened_at')
            ->get()
            ->map(fn (OperatorReenrolmentRequest $row): ReenrolmentRecord => $this->record($row))
            ->all());
    }

    private function record(OperatorReenrolmentRequest $row): ReenrolmentRecord
    {
        return new ReenrolmentRecord(
            id: $row->id,
            operatorId: $row->operator_id,
            state: ReenrolmentState::from($row->state),
            openedAt: $row->opened_at,
            expiresAt: $row->expires_at,
            closedAt: $row->closed_at,
            closedBy: $row->closed_by,
        );
    }
}
