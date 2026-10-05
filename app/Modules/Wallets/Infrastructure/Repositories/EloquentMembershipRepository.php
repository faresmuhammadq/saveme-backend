<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Membership\MemberRecord;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentMembershipRepository implements MembershipRepository
{
    public function activeFor(string $walletId, string $userId): ?MemberRecord
    {
        $member = WalletMember::query()
            ->where('wallet_id', $walletId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        return $member === null ? null : self::record($member);
    }

    public function atDeletion(string $walletId, string $userId, DateTimeImmutable $deletedAt): ?MemberRecord
    {
        $member = WalletMember::query()
            ->where('wallet_id', $walletId)
            ->where('user_id', $userId)
            ->whereRaw('deleted_at = ?::timestamptz', [self::instant($deletedAt)])
            ->first();

        return $member === null ? null : self::record($member);
    }

    public function ownerOf(string $walletId): ?string
    {
        $owner = WalletMember::query()
            ->where('wallet_id', $walletId)
            ->where('role', Role::Owner->value)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $owner === null ? null : (string) $owner;
    }

    public function activeMembersOf(string $walletId, ?array $after, int $limit): array
    {
        $query = WalletMember::query()->where('wallet_id', $walletId)->whereNull('deleted_at');

        if ($after !== null) {
            $query->whereRaw('(created_at, id) > (?::timestamptz, ?::uuid)', [$after['value'], $after['id']]);
        }

        return array_values($query->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get()
            ->map(fn (WalletMember $member): MemberRecord => self::record($member))
            ->all());
    }

    public function insert(string $walletId, string $userId, Role $role, int $changeSeq, int $memberChangeSeq): MemberRecord
    {
        $member = WalletMember::query()->create([
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'role' => $role->value,
            'version' => 1,
            'change_seq' => $changeSeq,
            'member_change_seq' => $memberChangeSeq,
        ]);

        return self::record($member->refresh());
    }

    public function changeRole(string $memberId, Role $role, int $changeSeq, int $memberChangeSeq): void
    {
        WalletMember::query()->whereKey($memberId)->toBase()->update([
            'role' => $role->value,
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'member_change_seq' => $memberChangeSeq,
            'updated_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function tombstoneAllIn(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq, Closure $nextMemberChangeSeq): void
    {
        $members = WalletMember::query()->where('wallet_id', $walletId)->whereNull('deleted_at')
            ->orderBy('user_id')->orderBy('id')->get(['id', 'user_id']);

        foreach ($members as $member) {
            WalletMember::query()->whereKey($member->id)->toBase()->update([
                'deleted_at' => self::instant($at),
                'version' => DB::raw('version + 1'),
                'change_seq' => $nextChangeSeq(),
                'member_change_seq' => $nextMemberChangeSeq($member->user_id),
                'updated_at' => self::instant($at),
            ]);
        }
    }

    public function tombstone(string $memberId, DateTimeImmutable $at, int $changeSeq, int $memberChangeSeq): void
    {
        WalletMember::query()->whereKey($memberId)->toBase()->update([
            'deleted_at' => self::instant($at),
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'member_change_seq' => $memberChangeSeq,
            'updated_at' => self::instant($at),
        ]);
    }

    public function latestFor(string $walletId, string $userId): ?MemberRecord
    {
        $member = WalletMember::query()
            ->where('wallet_id', $walletId)
            ->where('user_id', $userId)
            ->orderByRaw('deleted_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $member === null ? null : self::record($member);
    }

    public function activeRanked(string $walletId): array
    {
        return array_values(WalletMember::query()
            ->where('wallet_id', $walletId)
            ->whereNull('deleted_at')
            ->orderByRaw("role = 'owner' DESC")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WalletMember $member): MemberRecord => self::record($member))
            ->all());
    }

    public function countActive(string $walletId): int
    {
        return WalletMember::query()->where('wallet_id', $walletId)->whereNull('deleted_at')->count();
    }

    public function ownedLiveWalletsOf(string $userId): array
    {
        return array_values(WalletMember::query()
            ->join('wallets', 'wallets.id', '=', 'wallet_members.wallet_id')
            ->where('wallet_members.user_id', $userId)
            ->where('wallet_members.role', Role::Owner->value)
            ->whereNull('wallet_members.deleted_at')
            ->whereNull('wallets.deleted_at')
            ->orderBy('wallets.id')
            ->pluck('wallets.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    public function activeAmong(string $walletId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(WalletMember::query()
            ->where('wallet_id', $walletId)
            ->whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    public function liveWalletIdsOf(string $userId, string $afterWalletId, int $limit): array
    {
        $query = WalletMember::query()
            ->join('wallets', 'wallets.id', '=', 'wallet_members.wallet_id')
            ->where('wallet_members.user_id', $userId)
            ->whereNull('wallet_members.deleted_at')
            ->whereNull('wallets.deleted_at');

        if ($afterWalletId !== '') {
            $query->where('wallets.id', '>', $afterWalletId);
        }

        return array_values($query->orderBy('wallets.id')->limit($limit)->pluck('wallets.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    public function countsFor(string $userId): array
    {
        $live = fn () => DB::table('wallet_members')
            ->join('wallets', 'wallets.id', '=', 'wallet_members.wallet_id')
            ->whereNull('wallet_members.deleted_at')
            ->whereNull('wallets.deleted_at');

        $owned = $live()->where('wallet_members.user_id', $userId)->where('wallet_members.role', Role::Owner->value)->count();
        $joined = $live()->where('wallet_members.user_id', $userId)->where('wallet_members.role', '!=', Role::Owner->value)->count();
        $others = $live()
            ->where('wallet_members.user_id', '!=', $userId)
            ->whereIn('wallet_members.wallet_id', fn ($query) => $query->select('owned.wallet_id')->from('wallet_members as owned')
                ->where('owned.user_id', $userId)->where('owned.role', Role::Owner->value)->whereNull('owned.deleted_at'))
            ->distinct()
            ->count('wallet_members.user_id');

        return ['owned' => $owned, 'joined' => $joined, 'others' => $others];
    }

    public function countSharedWallets(): int
    {
        $shared = DB::table('wallet_members')
            ->join('wallets', 'wallets.id', '=', 'wallet_members.wallet_id')
            ->whereNull('wallet_members.deleted_at')
            ->whereNull('wallets.deleted_at')
            ->groupBy('wallet_members.wallet_id')
            ->havingRaw('count(*) >= 2')
            ->select('wallet_members.wallet_id');

        return DB::query()->fromSub($shared, 'shared')->count();
    }

    public function restamp(string $memberId, int $changeSeq): void
    {
        WalletMember::query()->whereKey($memberId)->toBase()->update(['change_seq' => $changeSeq]);
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public static function record(WalletMember $member): MemberRecord
    {
        return new MemberRecord(
            id: $member->id,
            walletId: $member->wallet_id,
            userId: $member->user_id,
            role: Role::from($member->role),
            version: $member->version,
            changeSeq: $member->change_seq,
            createdAt: $member->created_at->toDateTimeImmutable(),
            updatedAt: $member->updated_at->toDateTimeImmutable(),
            deletedAt: $member->deleted_at?->toDateTimeImmutable(),
            memberChangeSeq: (int) $member->member_change_seq,
        );
    }
}
