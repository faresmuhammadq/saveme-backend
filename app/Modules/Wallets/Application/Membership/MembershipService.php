<?php

namespace App\Modules\Wallets\Application\Membership;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\MemberRoleDetail;
use App\Modules\Identity\Application\Audit\Details\MembershipDetail;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Wallets\Application\Activity\WalletActivity;
use App\Modules\Wallets\Application\ScopeWrite;
use App\Modules\Wallets\Application\WalletScopeWrites;
use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence;
use App\Modules\Wallets\Domain\Membership\DemotionPlan;
use App\Modules\Wallets\Domain\Membership\MemberRecord;
use App\Modules\Wallets\Domain\Membership\Refusals\OwnerMembershipImmutable;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Every write that creates, moves or ends a membership after the wallet's
 * creation (spec 016, R-9; spec 010 FR-018): grant (an accepted invitation),
 * role change, removal, leaving and plan demotion. Membership rows are
 * written only here.
 *
 * Each write runs in the wallet's scope (WalletScopeWrites: wallet row lock,
 * then the wallet's final stamp, joining a caller's open scope for the same
 * wallet). The membership takes its `change_seq` from the wallet's counter
 * and its `member_change_seq` from its member's user counter (ADR-0017 rules
 * 2, 5: the wallet lock first, then user rows, several people in ascending
 * user id). Each write records one audit entry (Identity's log) and one
 * activity entry, in the same unit of work. A write that changes nothing (a
 * role already held, a membership already ended) stamps and records nothing.
 *
 * Authorization, quota and version checks belong to the caller (Sharing's
 * MemberService); this service refuses only what would break the model: the
 * owner's membership cannot be changed or ended here.
 */
final class MembershipService
{
    public function __construct(
        private readonly MembershipRepository $members,
        private readonly WalletScopeWrites $scopes,
        private readonly UserScopeSequence $userSequence,
        private readonly AuditLog $audit,
        private readonly WalletActivity $activity,
    ) {}

    /**
     * Makes the person a member with the role (version 1); an accepted
     * invitation. An active membership is returned unchanged.
     *
     * @throws WalletNotFound
     */
    public function grant(string $walletId, string $userId, Role $role): MemberRecord
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($walletId, $userId, $role): MemberRecord {
            $active = $this->members->activeFor($walletId, $userId);

            if ($active !== null) {
                return $active;
            }

            if ($role === Role::Owner || $scope->wallet->isTombstone()) {
                throw $role === Role::Owner ? new OwnerMembershipImmutable : new WalletNotFound;
            }

            $member = $this->members->insert($walletId, $userId, $role, $scope->nextChangeSeq(), $this->userSequence->next($userId));

            $this->audit->record(AuditRecord::byUser(AuditEvent::MembershipGranted, $userId, SubjectType::Wallet, $walletId, new MembershipDetail($userId, 'invitation')));
            $this->activity->record($walletId, $userId, ActivityAction::Joined, ActivitySubject::Member, $userId, $role);

            return $member;
        });
    }

    /**
     * Moves an active member between editor and viewer, version + 1.
     * Naming the role they already hold changes nothing.
     *
     * @throws WalletNotFound when the person is not an active member
     * @throws OwnerMembershipImmutable for the owner, or for the role `owner`
     */
    public function changeRole(string $walletId, string $actorId, string $memberUserId, Role $to): MemberRecord
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($walletId, $actorId, $memberUserId, $to): MemberRecord {
            $member = $this->members->activeFor($walletId, $memberUserId) ?? throw new WalletNotFound;

            if ($scope->wallet->isTombstone()) {
                throw new WalletNotFound;
            }

            if ($member->role === Role::Owner || $to === Role::Owner) {
                throw new OwnerMembershipImmutable;
            }

            if ($member->role === $to) {
                return $member;
            }

            $this->members->changeRole($member->id, $to, $scope->nextChangeSeq(), $this->userSequence->next($memberUserId));

            $this->audit->record(AuditRecord::byUser(AuditEvent::RoleChanged, $actorId, SubjectType::Wallet, $walletId, new MemberRoleDetail($memberUserId, $member->role->value, $to->value)));
            $this->activity->record($walletId, $actorId, ActivityAction::RoleChanged, ActivitySubject::Member, $memberUserId, $to);

            return $this->reload($walletId, $memberUserId);
        });
    }

    /**
     * Ends the member's membership at the owner's request: a tombstone,
     * version + 1. The person's newest membership already ended is returned
     * unchanged; one that never existed is not-found.
     *
     * @throws WalletNotFound
     * @throws WalletActionForbidden for the owner
     */
    public function remove(string $walletId, string $actorId, string $memberUserId): MemberRecord
    {
        return $this->end($walletId, $actorId, $memberUserId, 'removed', ActivityAction::Removed);
    }

    /**
     * Ends the person's own membership. Not the owner's: ownership moves
     * first (W-05).
     *
     * @throws WalletNotFound when the person is not an active member
     * @throws WalletActionForbidden for the owner
     */
    public function leave(string $walletId, string $userId): MemberRecord
    {
        return $this->end($walletId, $userId, $userId, 'left', ActivityAction::Left);
    }

    /**
     * Turns every editor ranked beyond the quota into a viewer, with actor
     * `system`: the owner counts as 1, then members by joining order. Never
     * removes, never promotes. Running it again changes nothing.
     *
     * @param  int|null  $quota  null = unlimited
     * @return list<MemberRecord> the members changed, as they were before
     */
    public function demoteBeyond(string $walletId, ?int $quota): array
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($walletId, $quota): array {
            if ($scope->wallet->isTombstone()) {
                return [];
            }

            $beyond = DemotionPlan::editorsBeyond($this->members->activeRanked($walletId), $quota);
            usort($beyond, fn (MemberRecord $a, MemberRecord $b): int => strcmp($a->userId, $b->userId));

            foreach ($beyond as $member) {
                $this->members->changeRole($member->id, Role::Viewer, $scope->nextChangeSeq(), $this->userSequence->next($member->userId));

                $this->audit->record(new AuditRecord(AuditEvent::RoleChanged, ActorType::System, null, SubjectType::Wallet, $walletId, new MemberRoleDetail($member->userId, Role::Editor->value, Role::Viewer->value)));
                $this->activity->record($walletId, null, ActivityAction::RoleLimitedByPlan, ActivitySubject::Member, $member->userId, Role::Viewer);
            }

            return $beyond;
        });
    }

    /**
     * Re-sends the person's live memberships on their wallets' feeds after a
     * display-name change (ADR-0021): each takes the wallet's next
     * `change_seq` and nothing else moves. One wallet lock per unit of work,
     * no user row, wallets in ascending id, at most $batch wallets. Nothing
     * is audited, recorded or published. A membership that ended, or a
     * wallet that was deleted, since the listing is skipped.
     *
     * @return string|null the last wallet handled when more follow, else null
     */
    public function restampNames(string $userId, string $afterWalletId, int $batch = 50): ?string
    {
        $walletIds = $this->members->liveWalletIdsOf($userId, $afterWalletId, $batch + 1);
        $more = count($walletIds) > $batch;
        $handled = array_slice($walletIds, 0, $batch);
        $restamped = 0;

        foreach ($handled as $walletId) {
            try {
                $done = $this->scopes->within($walletId, function (ScopeWrite $scope) use ($walletId, $userId): bool {
                    $member = $this->members->activeFor($walletId, $userId);

                    if ($member === null || $scope->wallet->isTombstone()) {
                        return false;
                    }

                    $this->members->restamp($member->id, $scope->nextChangeSeq());

                    return true;
                });
            } catch (WalletNotFound) {
                $done = false;
            }

            $restamped += $done ? 1 : 0;
        }

        Log::info('member_names_restamped', [
            'wallets_restamped' => $restamped,
            'wallets_skipped' => count($handled) - $restamped,
            'continued' => $more,
        ]);

        return $more && $handled !== [] ? $handled[array_key_last($handled)] : null;
    }

    private function end(string $walletId, string $actorId, string $memberUserId, string $reason, ActivityAction $action): MemberRecord
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($walletId, $actorId, $memberUserId, $reason, $action): MemberRecord {
            $latest = $this->members->latestFor($walletId, $memberUserId) ?? throw new WalletNotFound;

            if ($scope->wallet->isTombstone()) {
                throw new WalletNotFound;
            }

            if ($latest->deletedAt !== null) {
                if ($reason === 'left') {
                    throw new WalletNotFound;
                }

                return $latest;
            }

            if ($latest->role === Role::Owner) {
                throw new WalletActionForbidden;
            }

            $this->members->tombstone($latest->id, now()->toDateTimeImmutable(), $scope->nextChangeSeq(), $this->userSequence->next($memberUserId));

            $this->audit->record(AuditRecord::byUser(AuditEvent::MembershipRevoked, $actorId, SubjectType::Wallet, $walletId, new MembershipDetail($memberUserId, $reason)));
            $this->activity->record($walletId, $actorId, $action, ActivitySubject::Member, $memberUserId);

            return $this->reload($walletId, $memberUserId);
        });
    }

    private function reload(string $walletId, string $userId): MemberRecord
    {
        return $this->members->latestFor($walletId, $userId) ?? throw new RuntimeException('The membership vanished under the wallet lock.');
    }
}
