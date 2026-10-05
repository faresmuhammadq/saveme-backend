<?php

namespace App\Modules\Identity\Domain\Deletion;

/**
 * Who owns a shared wallet once its owner is gone (spec 022, FR-031, R-9):
 * the only other member, whatever their role; else the owner's choice if
 * still a member; else the longest-standing editor; else the
 * longest-standing viewer. In the last two steps a member whose own account
 * is leaving is skipped unless nobody else is left. One rule, used by the
 * preview and by the purge, so the two cannot differ for the same state.
 */
final class SuccessorRule
{
    /**
     * @param  list<SuccessorCandidate>  $candidates  the wallet's active members other than the departing person
     * @return Successor|null null when nobody else belongs: the wallet goes with the account
     */
    public static function resolve(array $candidates, ?string $chosenUserId): ?Successor
    {
        if ($candidates === []) {
            return null;
        }

        if (count($candidates) === 1) {
            return new Successor($candidates[0]->userId, SuccessorReason::OnlyMember);
        }

        if ($chosenUserId !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate->userId === $chosenUserId) {
                    return new Successor($candidate->userId, SuccessorReason::Chosen);
                }
            }
        }

        $editor = self::longestStanding($candidates, 'editor');

        if ($editor !== null) {
            return new Successor($editor->userId, SuccessorReason::OldestEditor);
        }

        $viewer = self::longestStanding($candidates, 'viewer');

        return $viewer === null ? null : new Successor($viewer->userId, SuccessorReason::OldestViewer);
    }

    /**
     * @param  list<SuccessorCandidate>  $candidates
     */
    private static function longestStanding(array $candidates, string $role): ?SuccessorCandidate
    {
        $ofRole = array_values(array_filter($candidates, fn (SuccessorCandidate $c): bool => $c->role === $role));
        $staying = array_values(array_filter($ofRole, fn (SuccessorCandidate $c): bool => ! $c->accountPending));
        $pool = $staying !== [] ? $staying : $ofRole;

        if ($pool === []) {
            return null;
        }

        usort($pool, function (SuccessorCandidate $a, SuccessorCandidate $b): int {
            return [$a->joinedAt->format('Y-m-d H:i:s.u'), $a->membershipId, $a->userId]
                <=> [$b->joinedAt->format('Y-m-d H:i:s.u'), $b->membershipId, $b->userId];
        });

        return $pool[0];
    }
}
