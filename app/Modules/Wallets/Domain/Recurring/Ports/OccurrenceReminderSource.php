<?php

namespace App\Modules\Wallets\Domain\Recurring\Ports;

use App\Modules\Wallets\Domain\Membership\Role;
use DateTimeImmutable;

/**
 * Set-wise reads behind the reminder query (spec 023, research R-12): which
 * occurrences need someone now, and who belongs to their wallets.
 */
interface OccurrenceReminderSource
{
    /**
     * Occurrences needing attention at $now, ordered by id after $afterId. A
     * `scheduled`, undeleted occurrence of an active rule of a live wallet is
     * returned when its rule has no automatic confirmation and it is dated
     * the rule's today or yesterday (kind `due`), or when the server's
     * automatic confirmation was refused and it is dated within the rule's
     * last 2 days (kind `refused`). "Today" is in each rule's own timezone.
     *
     * @return list<array{occurrence_id: string, wallet_id: string, kind: string}>
     */
    public function needingAttention(DateTimeImmutable $now, ?string $afterId, int $limit): array;

    /**
     * The active members of the wallets whose role is among the given ones.
     *
     * @param  list<string>  $walletIds
     * @param  list<Role>  $roles
     * @return array<string, list<string>> user ids keyed by wallet id
     */
    public function membersOf(array $walletIds, array $roles): array;
}
