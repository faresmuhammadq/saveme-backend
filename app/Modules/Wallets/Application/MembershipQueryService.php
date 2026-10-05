<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Wallets\Application\Dto\MemberView;
use App\Modules\Wallets\Domain\Membership\MemberRecord;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use DateTimeZone;

/**
 * What other modules and the HTTP layer may ask about who is in a wallet
 * (constitution, Wallets & Ledger).
 */
final class MembershipQueryService
{
    public function __construct(
        private readonly MembershipRepository $members,
        private readonly DisplayNames $displayNames,
    ) {}

    /**
     * The wallet's current owner, or null when it has none (deleted or unknown).
     */
    public function ownerOf(string $walletId): ?string
    {
        return $this->members->ownerOf($walletId);
    }

    /**
     * The person's role in the wallet while their membership is active.
     */
    public function roleOf(string $userId, string $walletId): ?Role
    {
        return $this->members->activeFor($walletId, $userId)?->role;
    }

    /**
     * Active members in creation order, with the display names members may see.
     *
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<MemberView>, next: array{value: string, id: string}|null}
     */
    public function activeMembersOf(string $walletId, ?array $after, int $limit): array
    {
        $records = $this->members->activeMembersOf($walletId, $after, $limit);
        $hasMore = count($records) > $limit;
        $page = array_slice($records, 0, $limit);
        $names = $this->displayNames->of(array_map(fn ($member): string => $member->userId, $page));

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $last->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), 'id' => $last->id];
        }

        return [
            'items' => array_map(fn ($member): MemberView => new MemberView($member, $names[$member->userId] ?? null), $page),
            'next' => $next,
        ];
    }

    /**
     * The person's active membership of the wallet.
     */
    public function activeMember(string $walletId, string $userId): ?MemberRecord
    {
        return $this->members->activeFor($walletId, $userId);
    }

    /**
     * The person's newest membership of the wallet, live or tombstoned.
     */
    public function latestMember(string $walletId, string $userId): ?MemberRecord
    {
        return $this->members->latestFor($walletId, $userId);
    }

    /**
     * Active members of the wallet, the owner included.
     */
    public function countActive(string $walletId): int
    {
        return $this->members->countActive($walletId);
    }

    /**
     * Ids of the live wallets the person currently owns.
     *
     * @return list<string>
     */
    public function ownedLiveWalletIds(string $userId): array
    {
        return $this->members->ownedLiveWalletsOf($userId);
    }

    /**
     * Active members in joining order (the owner first), without display
     * names: who could inherit a wallet, for Identity's deletion plan.
     *
     * @return list<MemberRecord>
     */
    public function activeInJoiningOrder(string $walletId): array
    {
        return $this->members->activeRanked($walletId);
    }

    /**
     * A membership with the display name the other members may see.
     */
    public function view(MemberRecord $member): MemberView
    {
        return new MemberView($member, $this->displayNames->of([$member->userId])[$member->userId] ?? null);
    }
}
