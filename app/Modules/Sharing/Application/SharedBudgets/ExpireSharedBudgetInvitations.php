<?php

namespace App\Modules\Sharing\Application\SharedBudgets;

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetScopeWrites;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRepository;
use Closure;
use DateTimeImmutable;

/**
 * The hourly sweep for Shared Budget invitations, beside the wallet one:
 * closes pending invitations past their expiry as `expired`, each with its
 * own stamp, in batches of 200 per budget lock. Acceptance checks the instant
 * itself, so the sweep's timing is no security matter. Idempotent.
 */
final class ExpireSharedBudgetInvitations
{
    public const BATCH = 200;

    public function __construct(
        private readonly SharedBudgetInvitationRepository $invitations,
        private readonly SharedBudgetScopeWrites $scopes,
    ) {}

    /**
     * @return int invitations closed
     */
    public function run(DateTimeImmutable $now): int
    {
        $closed = 0;
        $skipped = [];

        while (true) {
            $batch = array_values(array_filter(
                $this->invitations->expiredBefore($now, self::BATCH + count($skipped)),
                fn ($invitation): bool => ! isset($skipped[$invitation->id]),
            ));

            if ($batch === []) {
                break;
            }

            $byBudget = [];
            foreach (array_slice($batch, 0, self::BATCH) as $invitation) {
                $byBudget[$invitation->sharedBudgetId][] = $invitation->id;
            }

            ksort($byBudget, SORT_STRING);

            foreach ($byBudget as $budgetId => $ids) {
                try {
                    $closed += $this->scopes->within((string) $budgetId, function (SharedBudgetRecord $budget, Closure $next) use ($ids, $now): int {
                        $count = 0;

                        foreach ($ids as $id) {
                            $invitation = $this->invitations->findForUpdate($id);

                            if ($invitation === null || $invitation->status !== InvitationStatus::Pending || $invitation->expiresAt > $now) {
                                continue;
                            }

                            $this->invitations->close($id, InvitationStatus::Expired, $now, $next(), null);
                            $count++;
                        }

                        return $count;
                    });
                } catch (SharedBudgetNotFound) {
                    foreach ($ids as $id) {
                        $skipped[$id] = true;
                    }
                }
            }
        }

        return $closed;
    }
}
