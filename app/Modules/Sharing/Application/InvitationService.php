<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Sharing\Application\Dto\InvitationView;
use App\Modules\Sharing\Application\Dto\SavedInvitation;
use App\Modules\Sharing\Application\Jobs\SendInvitationMail;
use App\Modules\Sharing\Domain\Events\InvitationSent;
use App\Modules\Sharing\Domain\InvitationChannel;
use App\Modules\Sharing\Domain\InvitationRecord;
use App\Modules\Sharing\Domain\InvitationRules;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\InvitedRole;
use App\Modules\Sharing\Domain\MemberQuota;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Sharing\Domain\Refusals\InvitationNotFound;
use App\Modules\Sharing\Domain\Refusals\InvitationStateInvalid;
use App\Modules\Sharing\Domain\Refusals\InvitationVersionConflict;
use App\Modules\Wallets\Application\Activity\WalletActivity;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Application\RecordIdentifiers;
use App\Modules\Wallets\Application\ScopeWrite;
use App\Modules\Wallets\Application\WalletScopeWrites;
use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Sending and withdrawing invitations (spec 016, FR-001 to FR-009). Each
 * write is one unit of work under the wallet row lock (WalletScopeWrites):
 * the invitation, and any invitation it supersedes, take their `change_seq`
 * from the wallet's counter and the wallet takes the last.
 *
 * Send precedence (spec section 6, after the route's verification gate,
 * identifier check and invite budget): body shape (the request), not-found,
 * forbidden, a version above 0 for no record, a plan without sharing, the
 * member quota, a write to a closed invitation, an identical replay, a stale
 * version, then immutable members. The sharing capability and the quota are
 * checked for a new invitation only, so an owner whose plan lapsed can still
 * replay an earlier create and withdraw an offer.
 *
 * The answer never depends on whether the address has an account: nothing
 * here looks an address up by account. The queued mail job decides the body
 * (R-4). No address and no token is ever logged (P-15 rule 4).
 */
final class InvitationService
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly WalletScopeWrites $scopes,
        private readonly WalletAuthorizer $authorizer,
        private readonly EntitlementService $entitlements,
        private readonly MembershipQueryService $members,
        private readonly RecordIdentifiers $identifiers,
        private readonly InvitationAddresses $addresses,
        private readonly InvitationViews $views,
        private readonly WalletActivity $activity,
    ) {}

    /**
     * Creates the invitation (`$version` 0 or omitted) or replays an identical
     * write.
     *
     * @param  Closure(InvitationView): array<string, mixed>  $present  how a read renders the invitation, for a conflict's `current`
     *
     * @throws WalletNotFound
     * @throws InvitationNotFound
     */
    public function send(string $actorId, string $walletId, string $invitationId, InvitedRole $role, ?string $inviteeEmail, int $version, Closure $present): SavedInvitation
    {
        $address = $this->normalise($inviteeEmail);

        $seen = $this->invitations->find($invitationId);

        if (($seen !== null && $seen->walletId !== $walletId) || ($seen === null && $this->identifiers->isTaken($invitationId))) {
            throw new InvitationNotFound;
        }

        $secret = InvitationSecret::generate();

        try {
            $saved = $this->scopes->within($walletId, function (ScopeWrite $scope) use ($actorId, $walletId, $invitationId, $role, $address, $version, $present, $secret): SavedInvitation {
                if ($scope->wallet->isTombstone()) {
                    throw new WalletNotFound;
                }

                $this->authorizer->authorize($actorId, $walletId, Permission::S02SendInvitation, checkCapability: false);

                $stored = $this->invitations->findForUpdate($invitationId);

                if ($stored !== null) {
                    return $this->replayOrRefuse($stored, $walletId, $role, $address['lookup'] ?? null, $version, $present);
                }

                if ($version > 0 || $this->identifiers->isTaken($invitationId)) {
                    throw new InvitationNotFound;
                }

                $created = $this->create($scope, $actorId, $walletId, $invitationId, $role, $address, $secret);

                return new SavedInvitation($this->views->of($created), true, $address === null ? $this->linkFor($secret) : null);
            });
        } catch (UniqueConstraintViolationException) {
            // The same id was created concurrently in another wallet: it is not this wallet's.
            throw new InvitationNotFound;
        }

        if ($saved->created) {
            $this->afterCreate($actorId, $saved, $secret);
        }

        return $saved;
    }

    /**
     * Withdraws a pending invitation (row S-06): closed as `revoked`, a
     * tombstone, its address erased, its token dead, its seat freed. Closing
     * a closed invitation changes nothing. No capability is checked.
     *
     * @param  Closure(InvitationView): array<string, mixed>  $present
     *
     * @throws WalletNotFound
     * @throws InvitationNotFound
     */
    public function revoke(string $actorId, string $walletId, string $invitationId, int $version, Closure $present): InvitationView
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($actorId, $walletId, $invitationId, $version, $present): InvitationView {
            if ($scope->wallet->isTombstone()) {
                throw new WalletNotFound;
            }

            $this->authorizer->memberRole($actorId, $walletId);

            $stored = $this->invitations->findForUpdate($invitationId);

            if ($stored === null || $stored->walletId !== $walletId) {
                throw new InvitationNotFound;
            }

            $this->authorizer->authorize($actorId, $walletId, Permission::S06RevokeInvitation);

            if ($stored->isTombstone()) {
                return $this->views->of($stored);
            }

            if ($version !== $stored->version) {
                throw new InvitationVersionConflict($present($this->views->of($stored)));
            }

            $this->invitations->close($stored->id, InvitationStatus::Revoked, now()->toDateTimeImmutable(), $scope->nextChangeSeq(), null);
            $this->activity->record($walletId, $actorId, ActivityAction::InvitationRevoked, ActivitySubject::Invitation, $stored->id);

            return $this->views->of($this->invitations->find($stored->id) ?? throw new InvitationNotFound);
        });
    }

    /**
     * Withdraws a pending invitation because the person who sent it is
     * leaving the product (spec 022, FR-043). Their own authority is used
     * while it holds: as the wallet's owner they revoke it themselves (row
     * S-06), with their name on the activity entry. Once the wallet has
     * another owner they can no longer withdraw it, so the system does, with
     * no actor. Closed or gone: nothing changes.
     *
     * @return bool whether this call closed it
     */
    public function revokeForDepartingInviter(string $inviterId, string $walletId, string $invitationId): bool
    {
        try {
            return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($inviterId, $walletId, $invitationId): bool {
                if ($scope->wallet->isTombstone()) {
                    return false;
                }

                $stored = $this->invitations->findForUpdate($invitationId);

                if ($stored === null || $stored->walletId !== $walletId || $stored->isTombstone()) {
                    return false;
                }

                $actor = $this->members->roleOf($inviterId, $walletId) === Role::Owner ? $inviterId : null;

                $this->invitations->close($stored->id, InvitationStatus::Revoked, now()->toDateTimeImmutable(), $scope->nextChangeSeq(), null);
                $this->activity->record($walletId, $actor, ActivityAction::InvitationRevoked, ActivitySubject::Invitation, $stored->id);

                return true;
            });
        } catch (WalletNotFound) {
            return false;
        }
    }

    /**
     * A write to an invitation that already exists: a closed one is a
     * conflict, an identical body is a replay, anything else a stale version
     * or an immutable member.
     *
     * @param  Closure(InvitationView): array<string, mixed>  $present
     */
    private function replayOrRefuse(InvitationRecord $stored, string $walletId, InvitedRole $role, ?string $lookup, int $version, Closure $present): SavedInvitation
    {
        if ($stored->walletId !== $walletId) {
            throw new InvitationNotFound;
        }

        if ($stored->isTombstone()) {
            throw new InvitationVersionConflict($present($this->views->of($stored)));
        }

        $violations = InvitationRules::immutableViolations($stored, $role, $lookup);

        if ($violations === []) {
            return new SavedInvitation($this->views->of($stored), false);
        }

        if ($version !== $stored->version) {
            throw new InvitationVersionConflict($present($this->views->of($stored)));
        }

        throw new InvitationStateInvalid($violations);
    }

    /**
     * Inserts a new invitation after the plan checks, superseding a pending
     * invitation to the same address.
     *
     * @param  array{address: string, lookup: string}|null  $address
     */
    private function create(ScopeWrite $scope, string $actorId, string $walletId, string $invitationId, InvitedRole $role, ?array $address, InvitationSecret $secret): InvitationRecord
    {
        $entitlements = $this->entitlements->forWallet($walletId);

        if (! $entitlements->allows(Feature::SharingEnabled)) {
            throw new FeatureNotEntitled(Feature::SharingEnabled);
        }

        $now = now()->toDateTimeImmutable();
        $superseded = $address === null ? null : $this->invitations->pendingFor($walletId, $address['lookup']);

        $canInvite = MemberQuota::canInvite(
            $this->members->countActive($walletId),
            $this->invitations->countLivePending($walletId, $now, $superseded?->id),
            $entitlements->quota(Feature::SharingMaxMembersPerWallet),
        );

        if (! $canInvite) {
            throw new FeatureNotEntitled(Feature::SharingMaxMembersPerWallet);
        }

        if ($superseded !== null) {
            $this->invitations->close($superseded->id, InvitationStatus::Revoked, $now, $scope->nextChangeSeq(), null);
        }

        $invitation = $this->invitations->insert(
            $invitationId,
            $walletId,
            $actorId,
            $role,
            InvitationRules::channelFor($address['address'] ?? null),
            $address === null ? null : $this->addresses->seal($actorId, $address['address']),
            $address['lookup'] ?? null,
            $secret->hash(),
            $now->modify('+'.(int) config('sharing.invitation_days').' days'),
            $scope->nextChangeSeq(),
        );

        $this->activity->record($walletId, $actorId, ActivityAction::Invited, ActivitySubject::Invitation, $invitationId, Role::from($role->value));

        return $invitation;
    }

    /**
     * Counts the applied create against the inviter's budget, queues the mail
     * of an email invitation and announces the invitation, all after commit.
     */
    private function afterCreate(string $actorId, SavedInvitation $saved, InvitationSecret $secret): void
    {
        $invitation = $saved->invitation->invitation;

        RateLimiter::hit(self::budgetKey($actorId), 86400);

        if ($invitation->channel === InvitationChannel::Email) {
            SendInvitationMail::dispatch($invitation->id, $secret->value)->afterCommit();
        }

        event(new InvitationSent(...Events::stamp(), invitationId: $invitation->id, walletId: $invitation->walletId, invitedBy: $actorId, channel: $invitation->channel->value, role: $invitation->role->value));

        // Record identifiers are debug-level only (security baseline, What must never be logged).
        Log::info('sharing_invitation_created', ['channel' => $invitation->channel->value, 'role' => $invitation->role->value]);
        Log::debug('sharing_invitation_created_ids', ['invitation_id' => $invitation->id, 'wallet_id' => $invitation->walletId]);
    }

    /**
     * The shareable link, which carries the token and nothing else.
     */
    private function linkFor(InvitationSecret $secret): string
    {
        return config('sharing.links.invitation_url').'?token='.$secret->value;
    }

    /**
     * @return array{address: string, lookup: string}|null
     *
     * @throws InvitationStateInvalid when the text is not an address
     */
    private function normalise(?string $inviteeEmail): ?array
    {
        if ($inviteeEmail === null) {
            return null;
        }

        try {
            return $this->addresses->normaliseAndLookup($inviteeEmail);
        } catch (InvalidArgumentException) {
            throw new InvitationStateInvalid([new FieldError('/invitee_email', ErrorCode::InvalidType)]);
        }
    }

    /**
     * The cache key of the inviter's 24-hour create budget (spec 016, FR-026).
     */
    public static function budgetKey(string $inviterId): string
    {
        return 'sharing-invite:'.$inviterId;
    }
}
