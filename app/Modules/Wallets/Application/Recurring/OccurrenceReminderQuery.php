<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceReminder;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Membership\PermissionMatrix;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceReminderSource;
use DateTimeImmutable;
use LogicException;

/**
 * What Notifications may ask Wallets: which occurrences need someone now and
 * which members are allowed to act on them (spec 023, FR-023, research R-12).
 * Due-ness stays derived (ADR-0018): nothing is stored; "today" is the rule's
 * own. Recipients are decided here by the permission matrix (R-02, the row a
 * confirmation is authorised with), so Notifications copies no matrix row
 * (P-08 rule 5). A viewer is never a recipient.
 */
final class OccurrenceReminderQuery
{
    public function __construct(private readonly OccurrenceReminderSource $source) {}

    /**
     * One page, ordered by occurrence id after the cursor. The id is a paging
     * tiebreak only, never a business order (P-06 rule 4).
     *
     * @return list<OccurrenceReminder>
     */
    public function needingAttention(DateTimeImmutable $now, ?string $afterOccurrenceId, int $limit): array
    {
        $rows = $this->source->needingAttention($now, $afterOccurrenceId, $limit);

        if ($rows === []) {
            return [];
        }

        $members = $this->source->membersOf(
            array_values(array_unique(array_map(fn (array $row): string => $row['wallet_id'], $rows))),
            $this->confirmingRoles(),
        );

        return array_map(fn (array $row): OccurrenceReminder => new OccurrenceReminder(
            $row['occurrence_id'],
            $row['wallet_id'],
            $row['kind'],
            $members[$row['wallet_id']] ?? [],
        ), $rows);
    }

    /**
     * @return list<Role>
     */
    private function confirmingRoles(): array
    {
        if (PermissionMatrix::capabilityFor(Permission::R02WriteRecurring) !== null) {
            // Today the row names no capability. If it ever does, the owner's entitlement must be asked here.
            throw new LogicException('Row R-02 gained a capability: the reminder query must check it.');
        }

        return PermissionMatrix::rolesFor(Permission::R02WriteRecurring);
    }
}
