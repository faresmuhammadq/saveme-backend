<?php

namespace App\Modules\Sharing\Infrastructure\Repositories;

use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRepository;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Sharing\Infrastructure\Models\SharedBudgetInvitation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentSharedBudgetInvitationRepository implements SharedBudgetInvitationRepository
{
    public function find(string $id): ?SharedBudgetInvitationRecord
    {
        $row = SharedBudgetInvitation::query()->find($id);

        return $row === null ? null : self::record($row);
    }

    public function findForUpdate(string $id): ?SharedBudgetInvitationRecord
    {
        $row = SharedBudgetInvitation::query()->whereKey($id)->lockForUpdate()->first();

        return $row === null ? null : self::record($row);
    }

    public function findByTokenHash(string $tokenHash): ?SharedBudgetInvitationRecord
    {
        $row = SharedBudgetInvitation::query()->where('token_hash', $tokenHash)->first();

        return $row === null ? null : self::record($row);
    }

    public function identifierTaken(string $id): bool
    {
        return SharedBudgetInvitation::query()->whereKey($id)->exists() || Invitation::query()->whereKey($id)->exists();
    }

    public function insert(string $id, string $budgetId, string $invitedBy, string $tokenHash, DateTimeImmutable $expiresAt, int $changeSeq): SharedBudgetInvitationRecord
    {
        $row = SharedBudgetInvitation::query()->forceCreate([
            'id' => $id,
            'shared_budget_id' => $budgetId,
            'invited_by' => $invitedBy,
            'token_hash' => $tokenHash,
            'status' => InvitationStatus::Pending->value,
            'expires_at' => self::instant($expiresAt),
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);

        return self::record($row->refresh());
    }

    public function close(string $id, InvitationStatus $status, DateTimeImmutable $at, int $changeSeq, ?string $responderUserId): void
    {
        SharedBudgetInvitation::query()->whereKey($id)->toBase()->update([
            'status' => $status->value,
            'deleted_at' => self::instant($at),
            'responder_user_id' => $responderUserId,
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'updated_at' => self::instant($at),
        ]);
    }

    public function countLivePending(string $budgetId, DateTimeImmutable $now, ?string $exceptId = null): int
    {
        return SharedBudgetInvitation::query()
            ->where('shared_budget_id', $budgetId)
            ->whereNull('deleted_at')
            ->whereRaw('expires_at > ?::timestamptz', [self::instant($now)])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->count();
    }

    public function changedIn(string $budgetId, int $after, int $limit): array
    {
        return array_values(SharedBudgetInvitation::query()
            ->where('shared_budget_id', $budgetId)
            ->where('change_seq', '>', $after)
            ->orderBy('change_seq')
            ->limit($limit)
            ->get()
            ->map(fn (SharedBudgetInvitation $row): SharedBudgetInvitationRecord => self::record($row))
            ->all());
    }

    public function pendingIn(string $budgetId): array
    {
        return array_values(SharedBudgetInvitation::query()
            ->where('shared_budget_id', $budgetId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SharedBudgetInvitation $row): SharedBudgetInvitationRecord => self::record($row))
            ->all());
    }

    public function expiredBefore(DateTimeImmutable $now, int $limit): array
    {
        return array_values(SharedBudgetInvitation::query()
            ->whereNull('deleted_at')
            ->whereRaw('expires_at <= ?::timestamptz', [self::instant($now)])
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (SharedBudgetInvitation $row): SharedBudgetInvitationRecord => self::record($row))
            ->all());
    }

    public function pendingInvitedBy(string $userId): array
    {
        return array_values(SharedBudgetInvitation::query()
            ->where('invited_by', $userId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedBudgetInvitation $row): SharedBudgetInvitationRecord => self::record($row))
            ->all());
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private static function record(SharedBudgetInvitation $row): SharedBudgetInvitationRecord
    {
        return new SharedBudgetInvitationRecord(
            $row->id, $row->shared_budget_id, $row->invited_by, $row->token_hash, InvitationStatus::from($row->status),
            $row->expires_at->toDateTimeImmutable(), $row->responder_user_id, $row->version, $row->change_seq,
            $row->created_at->toDateTimeImmutable(), $row->updated_at->toDateTimeImmutable(), $row->deleted_at?->toDateTimeImmutable(),
        );
    }
}
