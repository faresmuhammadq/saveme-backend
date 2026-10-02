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
