<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Account\UserRecord;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Infrastructure\Models\User;
use DateTimeImmutable;
use RuntimeException;

final class EloquentUserRepository implements UserRepository
{
    /** Instants keep microseconds; the query builder would drop them. */
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function create(string $id, string $sealedEmail, string $lookup, string $passwordHash): void
    {
        User::query()->forceCreate([
            'id' => $id,
            'email_ciphertext' => $sealedEmail,
            'email_lookup' => $lookup,
            'password_hash' => $passwordHash,
            'state' => AccountState::Active->value,
        ]);
    }

    public function find(string $id): ?UserRecord
    {
        $user = User::query()->find($id);

        return $user === null ? null : $this->record($user);
    }

    public function findByLookup(string $lookup): ?UserRecord
    {
        $user = User::query()->where('email_lookup', $lookup)->first();

        return $user === null ? null : $this->record($user);
    }

    public function createdAt(string $id): ?DateTimeImmutable
    {
        return User::query()->find($id)?->created_at->toDateTimeImmutable();
    }

    public function sealedEmail(string $id): string
    {
        return (string) $this->model($id)->email_ciphertext;
    }

    public function lookupOf(string $id): string
    {
        return (string) $this->model($id)->email_lookup;
    }

    public function updatePasswordHash(string $id, string $passwordHash): void
    {
        User::query()->whereKey($id)->update(['password_hash' => $passwordHash, 'updated_at' => now()->format(self::INSTANT)]);
    }

    public function markVerified(string $id): bool
    {
        return User::query()->whereKey($id)->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()->format(self::INSTANT), 'updated_at' => now()->format(self::INSTANT)]) === 1;
    }

    public function scheduleDeletion(string $id, DateTimeImmutable $requestedAt, DateTimeImmutable $scheduledFor): void
    {
        User::query()->whereKey($id)->where('state', AccountState::Active->value)->update([
            'state' => AccountState::PendingDeletion->value,
            'deletion_requested_at' => $requestedAt->format(self::INSTANT),
            'deletion_scheduled_for' => $scheduledFor->format(self::INSTANT),
            'updated_at' => now()->format(self::INSTANT),
        ]);
    }

    public function cancelDeletion(string $id): bool
    {
        return User::query()->whereKey($id)->where('state', AccountState::PendingDeletion->value)->update([
            'state' => AccountState::Active->value,
            'deletion_requested_at' => null,
            'deletion_scheduled_for' => null,
            'deletion_reminded_at' => null,
            'updated_at' => now()->format(self::INSTANT),
        ]) === 1;
    }

    public function markPurging(string $id, DateTimeImmutable $now): bool
    {
        return User::query()->whereKey($id)
            ->where('state', AccountState::PendingDeletion->value)
            ->where('deletion_scheduled_for', '<=', $now->format(self::INSTANT))
            ->toBase()
            ->update(['state' => AccountState::Purging->value, 'updated_at' => $now->format(self::INSTANT)]) === 1;
    }

    public function toHusk(string $id, DateTimeImmutable $now): void
    {
        User::query()->whereKey($id)->toBase()->update([
            'state' => AccountState::Purged->value,
            'purged_at' => $now->format(self::INSTANT),
            'email_ciphertext' => null,
            'email_lookup' => null,
            'password_hash' => null,
            'deletion_requested_at' => null,
            'deletion_scheduled_for' => null,
            'deletion_reminded_at' => null,
            'updated_at' => $now->format(self::INSTANT),
        ]);
    }

    public function purgeCandidates(DateTimeImmutable $now, int $limit): array
    {
        return array_values(User::query()
            ->where(fn ($query) => $query
                ->where('state', AccountState::Purging->value)
                ->orWhere(fn ($due) => $due->where('state', AccountState::PendingDeletion->value)->where('deletion_scheduled_for', '<=', $now->format(self::INSTANT))))
            ->orderBy('deletion_scheduled_for')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all());
    }

    public function dueReminders(DateTimeImmutable $now, int $days, int $limit): array
    {
        return array_values(User::query()
            ->where('state', AccountState::PendingDeletion->value)
            ->whereNull('deletion_reminded_at')
            ->where('deletion_scheduled_for', '<=', $now->modify("+{$days} days")->format(self::INSTANT))
            ->where('deletion_scheduled_for', '>', $now->format(self::INSTANT))
            ->orderBy('deletion_scheduled_for')
            ->limit($limit)
            ->pluck('id')
            ->all());
    }

    public function markReminded(string $id, DateTimeImmutable $now): bool
    {
        return User::query()->whereKey($id)
            ->where('state', AccountState::PendingDeletion->value)
            ->whereNull('deletion_reminded_at')
            ->toBase()
            ->update(['deletion_reminded_at' => $now->format(self::INSTANT)]) === 1;
    }

    public function deleteHusksBefore(DateTimeImmutable $cut): int
    {
        return User::query()
            ->where('state', AccountState::Purged->value)
            ->where('purged_at', '<', $cut->format(self::INSTANT))
            ->delete();
    }

    public function statesOf(array $ids): array
    {
        $states = [];

        foreach (User::query()->whereIn('id', $ids)->get(['id', 'state']) as $user) {
            $states[$user->id] = AccountState::from($user->state);
        }

        return $states;
    }

    public function figures(DateTimeImmutable $now): array
    {
        $live = fn () => User::query()->whereNotIn('state', [AccountState::Purging->value, AccountState::Purged->value]);
        $since = fn (int $days): int => $live()->where('created_at', '>=', $now->modify("-{$days} days")->format(self::INSTANT))->count();

        return [
            'total' => $live()->count(),
            'verified' => $live()->whereNotNull('email_verified_at')->count(),
            'pending_deletion' => User::query()->where('state', AccountState::PendingDeletion->value)->count(),
            'new_7_days' => $since(7),
            'new_30_days' => $since(30),
        ];
    }

    private function model(string $id): User
    {
        return User::query()->find($id) ?? throw new RuntimeException('No such user.');
    }

    private function record(User $user): UserRecord
    {
        return new UserRecord(
            id: $user->id,
            passwordHash: (string) $user->password_hash,
            state: AccountState::from($user->state),
            emailVerifiedAt: $user->email_verified_at?->toDateTimeImmutable(),
            deletionScheduledFor: $user->deletion_scheduled_for?->toDateTimeImmutable(),
            deletionRequestedAt: $user->deletion_requested_at?->toDateTimeImmutable(),
        );
    }
}
