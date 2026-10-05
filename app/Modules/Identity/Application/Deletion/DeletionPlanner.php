<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Deletion\Successor;
use App\Modules\Identity\Domain\Deletion\SuccessorCandidate;
use App\Modules\Identity\Domain\Deletion\SuccessorRule;
use App\Modules\Identity\Domain\Ports\SuccessorChoiceRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\InvalidSuccessorChoice;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Domain\Membership\Role;
use RuntimeException;

/**
 * The one place that decides who inherits a departing person's shared
 * wallets (spec 022, FR-033, A-10). The deletion preview, the answer to the
 * request and the purge's ResolveOwnedWallets step all come here, so they
 * cannot disagree for the same state. Wallets are read through Wallets'
 * MembershipQueryService (P-02).
 */
final class DeletionPlanner
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SuccessorChoiceRepository $choices,
        private readonly MembershipQueryService $memberships,
    ) {}

    public function plan(string $userId): DeletionPlan
    {
        $user = $this->users->find($userId) ?? throw new RuntimeException('No such account.');
        $chosen = $this->choices->forUser($userId);
        $entries = [];

        foreach ($this->memberships->ownedLiveWalletIds($userId) as $walletId) {
            $candidates = $this->candidatesOf($userId, $walletId);
            $successor = SuccessorRule::resolve($candidates, $chosen[$walletId] ?? null);

            if ($successor !== null) {
                $entries[] = new DeletionPlanEntry($walletId, $successor, count($candidates) > 1);
            }
        }

        return new DeletionPlan(
            state: $user->state === AccountState::PendingDeletion ? 'pending_deletion' : 'active',
            requestedAt: $user->deletionRequestedAt,
            scheduledFor: $user->deletionScheduledFor,
            successors: $entries,
        );
    }

    /**
     * Who would own the wallet now; null when nobody else belongs.
     */
    public function successorFor(string $userId, string $walletId): ?Successor
    {
        $chosen = $this->choices->forUser($userId);

        return SuccessorRule::resolve($this->candidatesOf($userId, $walletId), $chosen[$walletId] ?? null);
    }

    /**
     * Checks the choices a deletion request carries. Each wallet must be a
     * live wallet the person owns with another active member, once; each
     * member an active member of it other than the person.
     *
     * @param  list<array{wallet_id: string, member_user_id: string}>  $entries
     *
     * @throws InvalidSuccessorChoice
     */
    public function validateChoices(string $userId, array $entries): void
    {
        $owned = array_flip($this->memberships->ownedLiveWalletIds($userId));
        $seen = [];
        $errors = [];

        foreach ($entries as $position => $entry) {
            $walletId = $entry['wallet_id'];
            $candidates = isset($owned[$walletId]) ? $this->candidatesOf($userId, $walletId) : [];

            if ($candidates === [] || isset($seen[$walletId])) {
                $errors[] = new FieldError("/successors/{$position}/wallet_id", ErrorCode::InvalidEnumValue);
            }

            $seen[$walletId] = true;

            $isMember = false;

            foreach ($candidates as $candidate) {
                $isMember = $isMember || $candidate->userId === $entry['member_user_id'];
            }

            if (! $isMember) {
                $errors[] = new FieldError("/successors/{$position}/member_user_id", ErrorCode::InvalidEnumValue);
            }
        }

        if ($errors !== []) {
            throw new InvalidSuccessorChoice($errors);
        }
    }

    /**
     * The wallet's active members other than the person.
     *
     * @return list<SuccessorCandidate>
     */
    private function candidatesOf(string $userId, string $walletId): array
    {
        $members = array_values(array_filter(
            $this->memberships->activeInJoiningOrder($walletId),
            fn ($member): bool => $member->userId !== $userId,
        ));

        $states = $this->users->statesOf(array_map(fn ($member): string => $member->userId, $members));

        return array_map(fn ($member): SuccessorCandidate => new SuccessorCandidate(
            userId: $member->userId,
            role: $member->role === Role::Editor ? 'editor' : 'viewer',
            joinedAt: $member->createdAt,
            accountPending: ($states[$member->userId] ?? AccountState::Active) !== AccountState::Active,
            membershipId: $member->id,
        ), $members);
    }
}
