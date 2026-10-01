<?php

namespace App\Modules\Wallets\Domain\Ports;

use App\Modules\Wallets\Domain\Membership\MemberRecord;
use App\Modules\Wallets\Domain\Membership\Role;
use Closure;
use DateTimeImmutable;

interface MembershipRepository
{
    public function activeFor(string $walletId, string $userId): ?MemberRecord;

    /**
     * The person's membership as it was when the wallet was deleted, if it
     * was tombstoned by that deletion.
     */
    public function atDeletion(string $walletId, string $userId, DateTimeImmutable $deletedAt): ?MemberRecord;

    public function ownerOf(string $walletId): ?string;

    /**
     * Active members in creation order, one page plus one.
     *
     * @param  array{value: string, id: string}|null  $after
     * @return list<MemberRecord>
     */
    public function activeMembersOf(string $walletId, ?array $after, int $limit): array;

    public function insert(string $walletId, string $userId, Role $role, int $changeSeq): MemberRecord;

    /**
     * Moves the role, version + 1, with the given change_seq.
     */
    public function changeRole(string $memberId, Role $role, int $changeSeq): void;

    /**
     * Tombstones every active membership of the wallet, each with
     * version + 1 and its own change_seq from $nextChangeSeq.
     *
     * @param  Closure(): int  $nextChangeSeq
     */
    public function tombstoneAllIn(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void;
}
