<?php

namespace App\Modules\Sharing\Domain\SharedBudgets;

use App\Modules\Sharing\Domain\InvitationStatus;
use DateTimeImmutable;

/**
 * Persistence of Shared Budget invitations.
 */
interface SharedBudgetInvitationRepository
{
    public function find(string $id): ?SharedBudgetInvitationRecord;

    public function findForUpdate(string $id): ?SharedBudgetInvitationRecord;

    public function findByTokenHash(string $tokenHash): ?SharedBudgetInvitationRecord;

    /**
     * Whether the identifier already names an invitation of either kind (P-06 rule 3).
     */
    public function identifierTaken(string $id): bool;

    public function insert(string $id, string $budgetId, string $invitedBy, string $tokenHash, DateTimeImmutable $expiresAt, int $changeSeq): SharedBudgetInvitationRecord;

    public function close(string $id, InvitationStatus $status, DateTimeImmutable $at, int $changeSeq, ?string $responderUserId): void;

    /**
     * Pending invitations that have not expired, optionally without one.
     */
    public function countLivePending(string $budgetId, DateTimeImmutable $now, ?string $exceptId = null): int;

    /**
     * Pending and closed invitations of the budget above a position.
     *
     * @return list<SharedBudgetInvitationRecord>
     */
    public function changedIn(string $budgetId, int $after, int $limit): array;

    /**
     * The budget's live invitations, newest first.
     *
     * @return list<SharedBudgetInvitationRecord>
     */
    public function pendingIn(string $budgetId): array;

    /**
     * Pending invitations past their expiry, oldest first.
     *
     * @return list<SharedBudgetInvitationRecord>
     */
    public function expiredBefore(DateTimeImmutable $now, int $limit): array;

    /**
     * Pending invitations a person sent, across budgets.
     *
     * @return list<SharedBudgetInvitationRecord>
     */
    public function pendingInvitedBy(string $userId): array;
}
