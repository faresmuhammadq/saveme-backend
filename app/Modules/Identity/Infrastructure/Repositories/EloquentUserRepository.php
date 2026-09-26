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

    public function sealedEmail(string $id): string
    {
        return $this->model($id)->email_ciphertext;
    }

    public function lookupOf(string $id): string
    {
        return $this->model($id)->email_lookup;
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
            'updated_at' => now()->format(self::INSTANT),
        ]) === 1;
    }

    private function model(string $id): User
    {
        return User::query()->find($id) ?? throw new RuntimeException('No such user.');
    }

    private function record(User $user): UserRecord
    {
        return new UserRecord(
            id: $user->id,
            passwordHash: $user->password_hash,
            state: AccountState::from($user->state),
            emailVerifiedAt: $user->email_verified_at?->toDateTimeImmutable(),
            deletionScheduledFor: $user->deletion_scheduled_for?->toDateTimeImmutable(),
        );
    }
}
