<?php

namespace App\Modules\Admin\Domain\Ports;

use App\Modules\Admin\Domain\Operators\ReenrolmentRecord;
use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use DateTimeImmutable;

interface ReenrolmentStore
{
    public function findOpenFor(string $operatorId): ?ReenrolmentRecord;

    public function open(string $operatorId, DateTimeImmutable $openedAt, DateTimeImmutable $expiresAt): ReenrolmentRecord;

    /**
     * Reads the request with FOR UPDATE; call inside a transaction.
     */
    public function lockForUpdate(string $requestId): ?ReenrolmentRecord;

    public function addApproval(string $requestId, string $approverId, DateTimeImmutable $at): void;

    public function hasApproval(string $requestId, string $approverId): bool;

    public function approvalCount(string $requestId): int;

    public function close(string $requestId, ReenrolmentState $state, ?string $closedBy, DateTimeImmutable $at): void;

    /**
     * Open requests, oldest first.
     *
     * @return list<ReenrolmentRecord>
     */
    public function listOpen(): array;

    /**
     * Open requests whose expiry has passed.
     *
     * @return list<ReenrolmentRecord>
     */
    public function dueForExpiry(DateTimeImmutable $now): array;
}
