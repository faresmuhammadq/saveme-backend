<?php

namespace App\Modules\Sharing\Application\SharedBudgets;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetDirectory;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetScopeWrites;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetActionForbidden;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Sharing\Application\Dto\SavedSharedBudgetInvitation;
use App\Modules\Sharing\Application\InvitationService;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\MemberQuota;
use App\Modules\Sharing\Domain\Refusals\InvitationNotFound;
use App\Modules\Sharing\Domain\Refusals\InvitationVersionConflict;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRepository;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sending and withdrawing Shared Budget invitations (ADR-0027 rules 3 and 14).
 * Each write is one unit of work under the budget row lock
 * (SharedBudgetScopeWrites): the invitation takes its stamp from the budget's
 * counter and the budget takes the last. Only the owner invites, and only by
 * link in v1: nothing here knows an address, and the token exists once, in the
 * create's answer. The plan's sharing capability and the seat quota are
 * checked for a new invitation only, so an owner whose plan lapsed can still
 * replay an earlier create and withdraw an offer. No token is ever logged.
 */
final class SharedBudgetInvitationService
{
    public function __construct(
        private readonly SharedBudgetInvitationRepository $invitations,
        private readonly SharedBudgetScopeWrites $scopes,
        private readonly SharedBudgetDirectory $directory,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Creates the invitation (`$version` 0 or omitted) or replays it.
     *
     * @param  Closure(SharedBudgetInvitationRecord): array<string, mixed>  $present  how a read renders the invitation, for a conflict's `current`
     *
     * @throws SharedBudgetNotFound
     * @throws SharedBudgetActionForbidden
     * @throws InvitationNotFound
     * @throws FeatureNotEntitled
     */
    public function send(string $actorId, string $budgetId, string $invitationId, int $version, Closure $present): SavedSharedBudgetInvitation
    {
        $seen = $this->invitations->find($invitationId);

        if (($seen !== null && $seen->sharedBudgetId !== $budgetId) || ($seen === null && $this->identifiers($invitationId))) {
            throw new InvitationNotFound;
        }

        $secret = InvitationSecret::generate();

        try {
            $saved = $this->scopes->within($budgetId, function (SharedBudgetRecord $budget, Closure $next) use ($actorId, $invitationId, $version, $present, $secret): SavedSharedBudgetInvitation {
                $this->assertOwner($actorId, $budget);

                $stored = $this->invitations->findForUpdate($invitationId);

                if ($stored !== null) {
                    if ($stored->isTombstone() || ($version !== 0 && $version !== $stored->version)) {
                        throw new InvitationVersionConflict($present($stored));
                    }

                    return new SavedSharedBudgetInvitation($stored, false);
                }

                if ($version > 0 || $this->identifiers($invitationId)) {
                    throw new InvitationNotFound;
                }

                return new SavedSharedBudgetInvitation($this->create($budget, $actorId, $invitationId, $secret, $next), true, $this->linkFor($secret));
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvitationNotFound;
        }

        if ($saved->created) {
            RateLimiter::hit(InvitationService::budgetKey($actorId), 86400);
            Log::info('sharing_shared_budget_invitation_created');
            Log::debug('sharing_shared_budget_invitation_created_ids', ['invitation_id' => $invitationId, 'shared_budget_id' => $budgetId]);
        }

        return $saved;
    }

    /**
     * Withdraws a pending invitation: closed as `revoked`, a tombstone, its
     * token dead, its seat freed. Closing a closed invitation changes nothing.
     *
     * @param  Closure(SharedBudgetInvitationRecord): array<string, mixed>  $present
     */
    public function revoke(string $actorId, string $budgetId, string $invitationId, int $version, Closure $present): SharedBudgetInvitationRecord
    {
        return $this->scopes->within($budgetId, function (SharedBudgetRecord $budget, Closure $next) use ($actorId, $budgetId, $invitationId, $version, $present): SharedBudgetInvitationRecord {
            $this->assertOwner($actorId, $budget);

            $stored = $this->invitations->findForUpdate($invitationId);

            if ($stored === null || $stored->sharedBudgetId !== $budgetId) {
                throw new InvitationNotFound;
            }

            if ($stored->isTombstone()) {
                return $stored;
            }

            if ($version !== $stored->version) {
                throw new InvitationVersionConflict($present($stored));
            }

            $this->invitations->close($stored->id, InvitationStatus::Revoked, now()->toDateTimeImmutable(), $next(), null);

            return $this->invitations->find($stored->id) ?? throw new InvitationNotFound;
        });
    }

    /**
     * Withdraws a pending invitation because the person who sent it is
     * leaving the product (ADR-0027 rule 15). Closed or gone: nothing changes.
     *
     * @return bool whether this call closed it
     */
    public function revokeForDepartingInviter(string $budgetId, string $invitationId): bool
    {
        try {
            return $this->scopes->within($budgetId, function (SharedBudgetRecord $budget, Closure $next) use ($invitationId): bool {
                $stored = $this->invitations->findForUpdate($invitationId);

                if ($stored === null || $stored->sharedBudgetId !== $budget->id || $stored->isTombstone()) {
                    return false;
                }

                $this->invitations->close($stored->id, InvitationStatus::Revoked, now()->toDateTimeImmutable(), $next(), null);

                return true;
            });
        } catch (SharedBudgetNotFound) {
            return false;
        }
    }

    private function create(SharedBudgetRecord $budget, string $actorId, string $invitationId, InvitationSecret $secret, Closure $next): SharedBudgetInvitationRecord
    {
        $entitlements = $this->entitlements->forUser($budget->ownerId);

        if (! $entitlements->allows(Feature::SharingEnabled)) {
            throw new FeatureNotEntitled(Feature::SharingEnabled);
        }

        $now = now()->toDateTimeImmutable();

        $fits = MemberQuota::canInvite(
            $this->directory->activeMemberCount($budget->id),
            $this->invitations->countLivePending($budget->id, $now),
            $entitlements->quota(Feature::SharingMaxMembersPerBudget),
        );

        if (! $fits) {
            throw new FeatureNotEntitled(Feature::SharingMaxMembersPerBudget);
        }

        return $this->invitations->insert(
            $invitationId,
            $budget->id,
            $actorId,
            $secret->hash(),
            $now->modify('+'.(int) config('sharing.invitation_days').' days'),
            $next(),
        );
    }

    /**
     * Not-found for a non-member, forbidden for a member who is not the owner.
     */
    private function assertOwner(string $actorId, SharedBudgetRecord $budget): void
    {
        if ($budget->isTombstoned() || ! $this->directory->isActiveMember($budget->id, $actorId)) {
            throw new SharedBudgetNotFound;
        }

        if (! $this->directory->isOwner($budget->id, $actorId)) {
            throw new SharedBudgetActionForbidden;
        }
    }

    private function identifiers(string $id): bool
    {
        return $this->directory->identifierTaken($id) || $this->invitations->identifierTaken($id);
    }

    /**
     * The shareable link, which carries the token and nothing else.
     */
    private function linkFor(InvitationSecret $secret): string
    {
        return config('sharing.links.shared_budget_invitation_url').'?token='.$secret->value;
    }
}
