<?php

namespace App\Modules\Sharing\Application\SharedBudgets;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetDirectory;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetScopeWrites;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Sharing\Application\Dto\SharedBudgetInvitationPreview;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\MemberQuota;
use App\Modules\Sharing\Domain\Refusals\TokenNotLive;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRepository;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * What a person holding a Shared Budget token does with it (ADR-0027). Open
 * to an unverified account: joining puts nothing into a budget. Every refusal
 * of a token that works in no way is the one TokenNotLive, so a token reveals
 * nothing about whom it was for. An active member gets their membership back
 * and the invitation stays pending.
 */
final class SharedBudgetInvitationResponses
{
    public function __construct(
        private readonly SharedBudgetInvitationRepository $invitations,
        private readonly SharedBudgetScopeWrites $scopes,
        private readonly SharedBudgetDirectory $directory,
        private readonly SharedBudgetService $budgets,
        private readonly EntitlementService $entitlements,
        private readonly DisplayNames $displayNames,
    ) {}

    /**
     * What the token offers the caller; changes nothing.
     *
     * @throws TokenNotLive
     */
    public function preview(string $userId, string $token): SharedBudgetInvitationPreview
    {
        $invitation = $this->liveFor($this->find($token));
        $budget = $this->directory->find($invitation->sharedBudgetId);

        if ($budget === null || $budget->isTombstoned()) {
            throw new TokenNotLive;
        }

        $names = $this->displayNames->of([$budget->ownerId]);

        return new SharedBudgetInvitationPreview(
            $invitation->id,
            $budget->id,
            $budget->name,
            $budget->currency,
            $names[$budget->ownerId] ?? null,
            $this->directory->activeMemberCount($budget->id),
            $invitation->expiresAt,
            $this->directory->isActiveMember($budget->id, $userId),
        );
    }

    /**
     * Joins the budget as a member.
     *
     * @return array{SharedBudgetMemberRecord, ?string} the membership and the member's display name
     *
     * @throws TokenNotLive
     * @throws FeatureNotEntitled
     */
    public function accept(string $userId, string $token): array
    {
        $seen = $this->find($token);

        try {
            $member = $this->scopes->within($seen->sharedBudgetId, function (SharedBudgetRecord $budget, Closure $next) use ($userId, $seen): SharedBudgetMemberRecord {
                if ($budget->isTombstoned()) {
                    throw new TokenNotLive;
                }

                $invitation = $this->invitations->findForUpdate($seen->id) ?? throw new TokenNotLive;

                if ($invitation->isTombstone()) {
                    $retry = $invitation->status === InvitationStatus::Accepted && $invitation->responderUserId === $userId;

                    return $retry ? $this->activeMember($budget->id, $userId) : throw new TokenNotLive;
                }

                $this->liveFor($invitation);

                if ($this->directory->isActiveMember($budget->id, $userId)) {
                    return $this->activeMember($budget->id, $userId);
                }

                $this->assertSeat($budget, $invitation);

                $granted = $this->budgets->addMember($budget->id, $userId);
                $this->invitations->close($invitation->id, InvitationStatus::Accepted, now()->toDateTimeImmutable(), $next(), $userId);

                return $granted;
            });
        } catch (SharedBudgetNotFound) {
            throw new TokenNotLive;
        }

        Log::info('sharing_shared_budget_invitation_accepted');
        Log::debug('sharing_shared_budget_invitation_accepted_ids', ['invitation_id' => $seen->id, 'shared_budget_id' => $seen->sharedBudgetId]);

        return [$member, $this->displayNames->of([$userId])[$userId] ?? null];
    }

    /**
     * Turns the invitation down: closed as `declined`. Declining again, by the
     * same person, changes nothing.
     *
     * @throws TokenNotLive
     */
    public function decline(string $userId, string $token): void
    {
        $seen = $this->find($token);

        try {
            $this->scopes->within($seen->sharedBudgetId, function (SharedBudgetRecord $budget, Closure $next) use ($userId, $seen): void {
                if ($budget->isTombstoned()) {
                    throw new TokenNotLive;
                }

                $invitation = $this->invitations->findForUpdate($seen->id) ?? throw new TokenNotLive;

                if ($invitation->isTombstone()) {
                    if ($invitation->status === InvitationStatus::Declined && $invitation->responderUserId === $userId) {
                        return;
                    }

                    throw new TokenNotLive;
                }

                $this->liveFor($invitation);

                $this->invitations->close($invitation->id, InvitationStatus::Declined, now()->toDateTimeImmutable(), $next(), $userId);
            });
        } catch (SharedBudgetNotFound) {
            throw new TokenNotLive;
        }
    }

    /**
     * @throws TokenNotLive
     */
    private function find(string $token): SharedBudgetInvitationRecord
    {
        $secret = InvitationSecret::fromToken($token) ?? throw new TokenNotLive;

        return $this->invitations->findByTokenHash($secret->hash()) ?? throw new TokenNotLive;
    }

    /**
     * @throws TokenNotLive
     */
    private function liveFor(SharedBudgetInvitationRecord $invitation): SharedBudgetInvitationRecord
    {
        return $invitation->isLiveAt(now()->toDateTimeImmutable()) ? $invitation : throw new TokenNotLive;
    }

    /**
     * The owner's plan decides the seats (ADR-0027 rule 14): this invitation,
     * the budget's other pending ones and its members must fit.
     *
     * @throws FeatureNotEntitled
     */
    private function assertSeat(SharedBudgetRecord $budget, SharedBudgetInvitationRecord $invitation): void
    {
        $entitlements = $this->entitlements->forUser($budget->ownerId);

        if (! $entitlements->allows(Feature::SharingEnabled)) {
            throw new FeatureNotEntitled(Feature::SharingEnabled);
        }

        $fits = MemberQuota::canAccept(
            $this->directory->activeMemberCount($budget->id),
            $this->invitations->countLivePending($budget->id, now()->toDateTimeImmutable(), $invitation->id),
            $entitlements->quota(Feature::SharingMaxMembersPerBudget),
        );

        if (! $fits) {
            throw new FeatureNotEntitled(Feature::SharingMaxMembersPerBudget);
        }
    }

    private function activeMember(string $budgetId, string $userId): SharedBudgetMemberRecord
    {
        return $this->directory->memberOf($budgetId, $userId) ?? throw new TokenNotLive;
    }
}
