<?php

namespace App\Modules\Wallets\Application\Membership;

/**
 * How many wallets a person owns and has joined, and how many other people
 * share their wallets. Counts only (spec 021, FR-021).
 */
final readonly class MemberCounts
{
    public function __construct(
        public int $walletsOwned,
        public int $walletsJoined,
        public int $otherMembers,
    ) {}

    /**
     * @return array{wallets_owned: int, wallets_joined: int, other_members: int}
     */
    public function toArray(): array
    {
        return ['wallets_owned' => $this->walletsOwned, 'wallets_joined' => $this->walletsJoined, 'other_members' => $this->otherMembers];
    }
}
