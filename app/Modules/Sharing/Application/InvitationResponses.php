<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Sharing\Application\Dto\InvitationPreview;
use App\Modules\Sharing\Domain\Events\InvitationAccepted;
use App\Modules\Sharing\Domain\InvitationChannel;
use App\Modules\Sharing\Domain\InvitationRecord;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\MemberQuota;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Sharing\Domain\Refusals\TokenNotLive;
use App\Modules\Wallets\Application\Dto\MemberView;
use App\Modules\Wallets\Application\Membership\MembershipService;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Application\ScopeWrite;
use App\Modules\Wallets\Application\WalletScopeWrites;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use Illuminate\Support\Facades\Log;

/**
 * What the person holding a token may do with it: see what it offers, accept
 * it, decline it (spec 016, FR-010 to FR-014). The token is matched by hash;
 * every way a token can fail the caller is one answer, `TokenNotLive` (404):
 * unknown, closed, expired by its instant (whether or not the hourly sweep
 * has closed it), of a deleted wallet, or an email invitation addressed to a
 * different account. Open to an unverified account (R-8).
 *
 * Accept and decline carry no `version`: the caller holds no copy of the
 * invitation and the only transition kills the token (constitution check,
 * P-07). They are idempotent by token and person.
 *
 * Accepting is one unit of work under the wallet lock: the membership is
 * granted by Wallets' MembershipService (both stamps, audit, activity) and
 * the invitation closes as `accepted`, so a concurrent acceptance of the last
 * seat sees the quota and one of the two is refused (R-7).
 */
final class InvitationResponses
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly WalletScopeWrites $scopes,
        private readonly MembershipService $membership,
        private readonly MembershipQueryService $members,
        private readonly EntitlementService $entitlements,
        private readonly InvitationAddresses $addresses,
        private readonly InvitationQueryService $queries,
    ) {}

    /**
     * What the token offers the caller; changes nothing.
     *
     * @throws TokenNotLive
     */
    public function preview(string $userId, string $token): InvitationPreview
    {
        $invitation = $this->liveFor($userId, $this->find($token));

        return $this->queries->previewFor($invitation, $userId);
    }

    /**
     * Joins the wallet with the invitation's role. An active member gets
     * their membership back unchanged and the invitation stays pending; the
     * same person retrying after a lost answer gets their membership.
     *
     * @throws TokenNotLive
     * @throws FeatureNotEntitled
     */
    public function accept(string $userId, string $token): MemberView
    {
        $seen = $this->find($token);

        try {
            $member = $this->scopes->within($seen->walletId, function (ScopeWrite $scope) use ($userId, $seen): array {
                if ($scope->wallet->isTombstone()) {
                    throw new TokenNotLive;
                }

                $invitation = $this->invitations->findForUpdate($seen->id) ?? throw new TokenNotLive;

                if ($invitation->isTombstone()) {
                    $active = $invitation->status === InvitationStatus::Accepted && $invitation->responderUserId === $userId
                        ? $this->members->activeMember($invitation->walletId, $userId)
                        : null;

                    return [$active ?? throw new TokenNotLive, null];
                }

                $this->liveFor($userId, $invitation);

                $active = $this->members->activeMember($invitation->walletId, $userId);

                if ($active !== null) {
                    return [$active, null];
                }

                $this->assertSeatFor($invitation);

                $granted = $this->membership->grant($invitation->walletId, $userId, Role::from($invitation->role->value));
                $this->invitations->close($invitation->id, InvitationStatus::Accepted, now()->toDateTimeImmutable(), $scope->nextChangeSeq(), $userId);

                return [$granted, $invitation];
            });
        } catch (WalletNotFound) {
            throw new TokenNotLive;
        }

        [$record, $accepted] = $member;

        if ($accepted !== null) {
            event(new InvitationAccepted(...Events::stamp(), invitationId: $accepted->id, walletId: $accepted->walletId, memberUserId: $userId, role: $accepted->role->value));
            Log::info('sharing_invitation_accepted', ['channel' => $accepted->channel->value, 'role' => $accepted->role->value]);
            Log::debug('sharing_invitation_accepted_ids', ['invitation_id' => $accepted->id, 'wallet_id' => $accepted->walletId]);
        }

        return $this->members->view($record);
    }

    /**
     * Turns the invitation down: closed as `declined`, the address erased.
     * Declining again, by the same person, changes nothing.
     *
     * @throws TokenNotLive
     */
    public function decline(string $userId, string $token): void
    {
        $seen = $this->find($token);

        try {
            $this->scopes->within($seen->walletId, function (ScopeWrite $scope) use ($userId, $seen): void {
                if ($scope->wallet->isTombstone()) {
                    throw new TokenNotLive;
                }

                $invitation = $this->invitations->findForUpdate($seen->id) ?? throw new TokenNotLive;

                if ($invitation->isTombstone()) {
                    if ($invitation->status === InvitationStatus::Declined && $invitation->responderUserId === $userId) {
                        return;
                    }

                    throw new TokenNotLive;
                }

                $this->liveFor($userId, $invitation);

                $this->invitations->close($invitation->id, InvitationStatus::Declined, now()->toDateTimeImmutable(), $scope->nextChangeSeq(), $userId);
            });
        } catch (WalletNotFound) {
            throw new TokenNotLive;
        }
    }

    /**
     * The invitation behind a token, by hash.
     *
     * @throws TokenNotLive
     */
    private function find(string $token): InvitationRecord
    {
        $secret = InvitationSecret::fromToken($token) ?? throw new TokenNotLive;

        return $this->invitations->findByTokenHash($secret->hash()) ?? throw new TokenNotLive;
    }

    /**
     * The invitation when it can serve this caller now: pending, not past its
     * expiry instant, and for an email invitation addressed to the caller's
     * own address.
     *
     * @throws TokenNotLive
     */
    private function liveFor(string $userId, InvitationRecord $invitation): InvitationRecord
    {
        if (! $invitation->isLiveAt(now()->toDateTimeImmutable())) {
            throw new TokenNotLive;
        }

        if ($invitation->channel === InvitationChannel::Email
            && ($invitation->addressLookup === null || ! hash_equals($invitation->addressLookup, $this->addresses->lookupOfUser($userId)))) {
            throw new TokenNotLive;
        }

        return $invitation;
    }

    /**
     * The owner's plan must still share, and owner + members + the wallet's
     * other live pending invitations + this one must fit the quota.
     *
     * @throws FeatureNotEntitled
     */
    private function assertSeatFor(InvitationRecord $invitation): void
    {
        $entitlements = $this->entitlements->forWallet($invitation->walletId);

        if (! $entitlements->allows(Feature::SharingEnabled)) {
            throw new FeatureNotEntitled(Feature::SharingEnabled);
        }

        $fits = MemberQuota::canAccept(
            $this->members->countActive($invitation->walletId),
            $this->invitations->countLivePending($invitation->walletId, now()->toDateTimeImmutable(), $invitation->id),
            $entitlements->quota(Feature::SharingMaxMembersPerWallet),
        );

        if (! $fits) {
            throw new FeatureNotEntitled(Feature::SharingMaxMembersPerWallet);
        }
    }
}
