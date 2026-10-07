<?php

namespace App\Modules\Billing\Domain\Ports;

use App\Modules\Billing\Domain\GooglePlay\StoredPlayPurchase;
use DateTimeImmutable;

/**
 * Play Purchases (spec 024, data-model.md). A token is linked once, forever:
 * `linkTo` is a conditional update and the lookup value is unique.
 */
interface PlayPurchaseRepository
{
    public function find(string $id): ?StoredPlayPurchase;

    public function findByLookup(string $lookup): ?StoredPlayPurchase;

    /**
     * The row, locked until the transaction ends.
     */
    public function lock(string $id): ?StoredPlayPurchase;

    /**
     * Inserts an unlinked purchase unless the token lookup exists already
     * (`ON CONFLICT DO NOTHING`).
     *
     * @param  array{id: string, token_lookup: string, token_sealed: string, kind: string, product_id: string, obfuscated_ref: ?string, replaces_lookup: ?string, play_state: string, is_test: bool, base_plan_id: ?string, purchased_at: DateTimeImmutable}  $row
     * @return bool whether this call inserted it
     */
    public function insertIfAbsent(array $row): bool;

    /**
     * Links an unlinked purchase to an account, only if it is still unlinked.
     *
     * @return bool whether this call linked it
     */
    public function linkTo(string $id, string $userId, DateTimeImmutable $at): bool;

    /**
     * @param  array<string, string|bool|DateTimeImmutable|null>  $columns
     */
    public function update(string $id, array $columns): void;

    /**
     * @return list<StoredPlayPurchase>
     */
    public function forUser(string $userId): array;

    /**
     * Purchases whose subscription still grants by its stored dates but whose
     * period or grace end has passed (spec 024, FR-044a).
     *
     * @return list<string> ids
     */
    public function grantingPastEnd(DateTimeImmutable $now): array;

    /**
     * Linked purchases Google reports paid that it has not confirmed as acknowledged.
     *
     * @return list<string> ids
     */
    public function unacknowledged(): array;

    /**
     * Linked purchases Google still reports pending, bought since $since.
     *
     * @return list<string> ids
     */
    public function pendingSince(DateTimeImmutable $since): array;

    /**
     * Purchases whose subscription grants and that were last read before $cut.
     *
     * @return list<string> ids
     */
    public function grantingSyncedBefore(DateTimeImmutable $cut): array;

    /**
     * Linked, paid, unacknowledged purchases bought before $cut.
     */
    public function unacknowledgedBefore(DateTimeImmutable $cut): int;

    public function deleteUnlinkedBefore(DateTimeImmutable $cut): int;

    /**
     * Erases the sealed tokens of an account's purchases and stamps them as
     * ended by its purge, once (spec 024, FR-048).
     *
     * @return int rows changed
     */
    public function eraseForAccountPurge(string $userId, DateTimeImmutable $at): int;

    /**
     * Deletes the purchases stamped as ended by an account purge before $cut
     * (they must go before the subscriptions they point at).
     */
    public function deleteEndedByAccountPurgeBefore(DateTimeImmutable $cut): int;

    /**
     * Purchases linked in the window.
     */
    public function linkedSince(DateTimeImmutable $since): int;
}
