<?php

namespace App\Modules\Sharing\Infrastructure\Repositories;

use App\Modules\Sharing\Domain\InvitationChannel;
use App\Modules\Sharing\Domain\InvitationRecord;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\InvitedRole;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentInvitationRepository implements InvitationRepository
{
    public function find(string $id): ?InvitationRecord
    {
        $invitation = Invitation::query()->find($id);

        return $invitation === null ? null : self::record($invitation);
    }

    public function findForUpdate(string $id): ?InvitationRecord
    {
        $invitation = Invitation::query()->whereKey($id)->lockForUpdate()->first();

        return $invitation === null ? null : self::record($invitation);
    }

    public function findByTokenHash(string $tokenHash): ?InvitationRecord
    {
        $invitation = Invitation::query()->where('token_hash', $tokenHash)->first();

        return $invitation === null ? null : self::record($invitation);
    }

    public function pendingFor(string $walletId, string $lookup): ?InvitationRecord
    {
        $invitation = Invitation::query()
            ->where('wallet_id', $walletId)
            ->where('address_lookup', $lookup)
            ->whereNull('deleted_at')
            ->first();

        return $invitation === null ? null : self::record($invitation);
    }

    public function countLivePending(string $walletId, DateTimeImmutable $now, ?string $exceptId = null): int
    {
        $query = Invitation::query()
            ->where('wallet_id', $walletId)
            ->whereNull('deleted_at')
            ->whereRaw('expires_at > ?::timestamptz', [self::instant($now)]);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->count();
    }

    public function listLive(string $walletId, ?array $after, int $limit): array
    {
        $query = Invitation::query()->where('wallet_id', $walletId)->whereNull('deleted_at');

        if ($after !== null) {
            $query->whereRaw('(created_at, id) > (?::timestamptz, ?::uuid)', [$after['value'], $after['id']]);
        }

        return array_values($query->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get()
            ->map(fn (Invitation $invitation): InvitationRecord => self::record($invitation))
            ->all());
    }

    public function insert(string $id, string $walletId, string $invitedBy, InvitedRole $role, InvitationChannel $channel, ?string $addressSealed, ?string $addressLookup, string $tokenHash, DateTimeImmutable $expiresAt, int $changeSeq): InvitationRecord
    {
        $invitation = Invitation::query()->create([
            'id' => $id,
            'wallet_id' => $walletId,
            'invited_by' => $invitedBy,
            'role' => $role->value,
            'channel' => $channel->value,
            'address_sealed' => $addressSealed,
            'address_lookup' => $addressLookup,
            'token_hash' => $tokenHash,
            'status' => InvitationStatus::Pending->value,
            'expires_at' => self::instant($expiresAt),
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);

        return self::record($invitation->refresh());
    }

    public function close(string $id, InvitationStatus $status, DateTimeImmutable $at, int $changeSeq, ?string $responderUserId): void
    {
        Invitation::query()->whereKey($id)->whereNull('deleted_at')->toBase()->update([
            'status' => $status->value,
            'deleted_at' => self::instant($at),
            'updated_at' => self::instant($at),
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'address_sealed' => null,
            'address_lookup' => null,
            'responder_user_id' => $responderUserId,
        ]);
    }

    public function changedIn(string $walletId, int $after, int $limit): array
    {
        return array_values(Invitation::query()
            ->where('wallet_id', $walletId)
            ->where('change_seq', '>', $after)
            ->orderBy('change_seq')
            ->limit($limit)
            ->get()
            ->map(fn (Invitation $invitation): InvitationRecord => self::record($invitation))
            ->all());
    }

    public function expiredBefore(DateTimeImmutable $now, int $limit): array
    {
        return array_values(Invitation::query()
            ->whereNull('deleted_at')
            ->whereRaw('expires_at <= ?::timestamptz', [self::instant($now)])
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Invitation $invitation): InvitationRecord => self::record($invitation))
            ->all());
    }

    public function pendingIn(string $walletId): array
    {
        return array_values(Invitation::query()
            ->where('wallet_id', $walletId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Invitation $invitation): InvitationRecord => self::record($invitation))
            ->all());
    }

    public function pendingInvitedBy(string $userId): array
    {
        return array_values(Invitation::query()
            ->where('invited_by', $userId)
            ->whereNull('deleted_at')
            ->orderBy('wallet_id')
            ->orderBy('id')
            ->get()
            ->map(fn (Invitation $invitation): InvitationRecord => self::record($invitation))
            ->all());
    }

    public function sentBy(string $userId): array
    {
        return array_values(Invitation::query()
            ->where('invited_by', $userId)
            ->orderBy('wallet_id')
            ->orderBy('id')
            ->get()
            ->map(fn (Invitation $invitation): InvitationRecord => self::record($invitation))
            ->all());
    }

    public function nextExpiredTombstones(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return array_values(array_map(
            fn (object $row): array => ['id' => (string) ((array) $row)['id'], 'wallet_id' => (string) ((array) $row)['wallet_id'], 'change_seq' => (int) ((array) $row)['change_seq']],
            DB::select(
                'SELECT id, wallet_id, change_seq FROM invitations WHERE deleted_at < ?::timestamptz AND id > ?::uuid ORDER BY id LIMIT ?',
                [self::instant($before), $afterId === '' ? '00000000-0000-0000-0000-000000000000' : $afterId, $limit],
            ),
        ));
    }

    public function deleteTombstones(array $ids, DateTimeImmutable $before): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            fn (object $row): array => ['wallet_id' => (string) ((array) $row)['wallet_id'], 'change_seq' => (int) ((array) $row)['change_seq']],
            DB::select(
                'DELETE FROM invitations WHERE id = ANY(?::uuid[]) AND deleted_at < ?::timestamptz RETURNING wallet_id, change_seq',
                ['{'.implode(',', $ids).'}', self::instant($before)],
            ),
        ));
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public static function record(Invitation $invitation): InvitationRecord
    {
        return new InvitationRecord(
            id: $invitation->id,
            walletId: $invitation->wallet_id,
            invitedBy: $invitation->invited_by,
            role: InvitedRole::from($invitation->role),
            channel: InvitationChannel::from($invitation->channel),
            addressSealed: $invitation->address_sealed,
            addressLookup: $invitation->address_lookup,
            tokenHash: $invitation->token_hash,
            status: InvitationStatus::from($invitation->status),
            expiresAt: $invitation->expires_at->toDateTimeImmutable(),
            responderUserId: $invitation->responder_user_id,
            version: $invitation->version,
            changeSeq: $invitation->change_seq,
            createdAt: $invitation->created_at->toDateTimeImmutable(),
            updatedAt: $invitation->updated_at->toDateTimeImmutable(),
            deletedAt: $invitation->deleted_at?->toDateTimeImmutable(),
        );
    }
}
