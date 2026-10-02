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

    /**
     * Inserts version 1. `$memberChangeSeq` is the membership's position on
     * its member's user feed (ADR-0017 rule 2), stamped by the caller.
     */
    public function insert(string $walletId, string $userId, Role $role, int $changeSeq, int $memberChangeSeq): MemberRecord;

    /**
     * Moves the role, version + 1, with the given change_seq and user-feed stamp.
     */
    public function changeRole(string $memberId, Role $role, int $changeSeq, int $memberChangeSeq): void;

    /**
     * Tombstones every active membership of the wallet, each with
     * version + 1, its own change_seq from $nextChangeSeq and its user-feed
     * stamp from $nextMemberChangeSeq. Members are taken in ascending user
     * id, the order user rows are locked in (ADR-0017 rule 5).
     *
     * @param  Closure(): int  $nextChangeSeq
     * @param  Closure(string): int  $nextMemberChangeSeq  given the member's user id
     */
    public function tombstoneAllIn(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq, Closure $nextMemberChangeSeq): void;

    /**
     * Tombstones one membership: `deleted_at` = $at, version + 1, with the
     * given change_seq and user-feed stamp (ADR-0017 rule 4).
     */
    public function tombstone(string $memberId, DateTimeImmutable $at, int $changeSeq, int $memberChangeSeq): void;

    /**
     * The person's newest membership of the wallet, live or tombstoned.
     */
    public function latestFor(string $walletId, string $userId): ?MemberRecord;

    /**
     * Active members ranked for the plan quota: the owner first, then by
     * joining order (`created_at`, `id`).
     *
     * @return list<MemberRecord>
     */
    public function activeRanked(string $walletId): array;

    /**
     * Active members of the wallet, the owner included.
     */
    public function countActive(string $walletId): int;

    /**
     * Ids of the live wallets the person currently owns.
     *
     * @return list<string>
     */
    public function ownedLiveWalletsOf(string $userId): array;

    /**
     * Which of the given people are active members of the wallet.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function activeAmong(string $walletId, array $userIds): array;
}
