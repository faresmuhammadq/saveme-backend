<?php

namespace App\Modules\Sharing\Domain\Ports;

use App\Modules\Sharing\Domain\InvitationChannel;
use App\Modules\Sharing\Domain\InvitationRecord;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\InvitedRole;
use DateTimeImmutable;

/**
 * Persistence of invitations (spec 016). Every write takes its `change_seq`
 * from the wallet's counter, stamped by the caller under the wallet lock.
 */
interface InvitationRepository
{
    /**
     * Any invitation, tombstones included.
     */
    public function find(string $id): ?InvitationRecord;

    /**
     * As find(), taking the invitation's row lock.
     */
    public function findForUpdate(string $id): ?InvitationRecord;

    public function findByTokenHash(string $tokenHash): ?InvitationRecord;

    /**
     * The wallet's live pending invitation to the address lookup value.
     */
    public function pendingFor(string $walletId, string $lookup): ?InvitationRecord;

    /**
     * Pending invitations of the wallet that have not expired, optionally
     * without one.
     */
    public function countLivePending(string $walletId, DateTimeImmutable $now, ?string $exceptId = null): int;

    /**
     * Pending invitations oldest first, stabilised by id, one page plus one.
     *
     * @param  array{value: string, id: string}|null  $after
     * @return list<InvitationRecord>
     */
    public function listLive(string $walletId, ?array $after, int $limit): array;

    public function insert(
        string $id,
        string $walletId,
        string $invitedBy,
        InvitedRole $role,
        InvitationChannel $channel,
        ?string $addressSealed,
        ?string $addressLookup,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        int $changeSeq,
    ): InvitationRecord;

    /**
     * Closes a pending invitation: status, `deleted_at` = $at, version + 1,
     * the address and lookup value erased, the given change_seq.
     */
    public function close(string $id, InvitationStatus $status, DateTimeImmutable $at, int $changeSeq, ?string $responderUserId): void;

    /**
     * The wallet's invitations above a position, tombstones included, by
     * change_seq.
     *
     * @return list<InvitationRecord>
     */
    public function changedIn(string $walletId, int $after, int $limit): array;

    /**
     * Pending invitations of any wallet past their expiry, oldest first.
     *
     * @return list<InvitationRecord>
     */
    public function expiredBefore(DateTimeImmutable $now, int $limit): array;

    /**
     * Every pending invitation of the wallet.
     *
     * @return list<InvitationRecord>
     */
    public function pendingIn(string $walletId): array;

    /**
     * Every pending invitation a person sent, in any wallet, by wallet id
     * then id (the order wallets are locked in).
     *
     * @return list<InvitationRecord>
     */
    public function pendingInvitedBy(string $userId): array;

    /**
     * Every invitation a person sent, pending or closed while its tombstone
     * is kept, by wallet id then id.
     *
     * @return list<InvitationRecord>
     */
    public function sentBy(string $userId): array;

    /**
     * Tombstones closed before the cut, after the id, oldest id first.
     *
     * @return list<array{id: string, wallet_id: string, change_seq: int}>
     */
    public function nextExpiredTombstones(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * Deletes the tombstones closed before the cut among the ids; returns the
     * rows removed.
     *
     * @param  list<string>  $ids
     * @return list<array{wallet_id: string, change_seq: int}>
     */
    public function deleteTombstones(array $ids, DateTimeImmutable $before): array;
}
