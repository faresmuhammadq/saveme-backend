<?php

namespace App\Modules\Wallets\Domain\Membership;

/**
 * Which editors a lowered member quota turns into viewers (spec 016, R-11).
 * Members rank owner first, then by joining order; the owner is rank 1 and
 * counts toward the quota. Only editors ranked beyond the quota are returned:
 * a viewer is never touched, nobody is removed and nobody is promoted.
 */
final class DemotionPlan
{
    /**
     * @param  list<MemberRecord>  $ranked  active members, owner first, then joining order
     * @param  int|null  $quota  null = unlimited
     * @return list<MemberRecord>
     */
    public static function editorsBeyond(array $ranked, ?int $quota): array
    {
        if ($quota === null) {
            return [];
        }

        $beyond = [];

        foreach ($ranked as $index => $member) {
            if ($index + 1 > $quota && $member->role === Role::Editor) {
                $beyond[] = $member;
            }
        }

        return $beyond;
    }
}
