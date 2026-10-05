<?php

namespace App\Modules\Admin\Infrastructure\Repositories;

use App\Modules\Admin\Domain\Operators\OperatorRecord;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\SecretSealer;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Operator Accounts, with sealed values opened on the way out and sealed on
 * the way in (spec 018, R-10).
 */
final class EloquentOperatorRepository implements OperatorRepository
{
    public function __construct(
        private readonly SecretSealer $sealer,
        private readonly EmailLookup $lookups,
    ) {}

    public function find(string $id): ?OperatorRecord
    {
        $row = OperatorAccount::query()->find($id);

        return $row === null ? null : $this->record($row);
    }

    public function findByLookup(string $lookup): ?OperatorRecord
    {
        $row = OperatorAccount::query()->where('email_lookup', $lookup)->first();

        return $row === null ? null : $this->record($row);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(OperatorAccount::query()->whereIn('id', array_values(array_unique($ids)))->get()
            ->map(fn (OperatorAccount $row): OperatorRecord => $this->record($row))
            ->all());
    }

    public function lockForUpdate(string $id): ?OperatorRecord
    {
        $row = OperatorAccount::query()->whereKey($id)->lockForUpdate()->first();

        return $row === null ? null : $this->record($row);
    }

    public function create(string $email, string $displayName, string $passwordHash, DateTimeImmutable $temporaryPasswordExpiresAt): OperatorRecord
    {
        $id = (string) Str::uuid7();

        $row = OperatorAccount::query()->create([
            'id' => $id,
            'email_lookup' => $this->lookups->lookup($email),
            'email_sealed' => $this->sealer->seal($email, $id, 'email'),
            'display_name_sealed' => $this->sealer->seal($displayName, $id, 'display_name'),
            'password_hash' => $passwordHash,
            'state' => OperatorState::AwaitingPasswordChange->value,
            'temporary_password_expires_at' => $temporaryPasswordExpiresAt,
            'credential_epoch' => 0,
        ]);

        return $this->record($row->refresh());
    }

    public function countActive(): int
    {
        return OperatorAccount::query()->where('state', OperatorState::Active->value)->count();
    }

    public function countEligibleApprovers(string $exceptId): int
    {
        return OperatorAccount::query()
            ->where('state', OperatorState::Active->value)
            ->whereNotNull('secret_sealed')
            ->whereKeyNot($exceptId)
            ->count();
    }

    public function changePassword(string $id, string $passwordHash, OperatorState $state, bool $bumpEpoch): int
    {
        $row = OperatorAccount::query()->findOrFail($id);
        $row->forceFill([
            'password_hash' => $passwordHash,
            'state' => $state->value,
            'temporary_password_expires_at' => null,
            'password_changed_at' => now(),
            'credential_epoch' => $row->credential_epoch + ($bumpEpoch ? 1 : 0),
        ])->save();

        return $row->credential_epoch;
    }

    public function issueTemporaryPassword(string $id, string $passwordHash, DateTimeImmutable $expiresAt): void
    {
        $row = OperatorAccount::query()->findOrFail($id);
        $row->forceFill([
            'password_hash' => $passwordHash,
            'state' => OperatorState::AwaitingPasswordChange->value,
            'temporary_password_expires_at' => $expiresAt,
            'credential_epoch' => $row->credential_epoch + 1,
        ])->save();
    }

    public function updatePasswordHash(string $id, string $passwordHash): void
    {
        OperatorAccount::query()->whereKey($id)->update(['password_hash' => $passwordHash]);
    }

    public function deactivate(string $id): void
    {
        $row = OperatorAccount::query()->findOrFail($id);
        $row->forceFill([
            'state' => OperatorState::Deactivated->value,
            'pending_secret_sealed' => null,
            'pending_secret_created_at' => null,
            'credential_epoch' => $row->credential_epoch + 1,
        ])->save();
    }

    public function storePendingSecret(string $id, string $secret, DateTimeImmutable $at): void
    {
        OperatorAccount::query()->findOrFail($id)->forceFill([
            'pending_secret_sealed' => $this->sealer->seal($secret, $id, 'pending_secret'),
            'pending_secret_created_at' => $at,
        ])->save();
    }

    public function clearPendingSecret(string $id): void
    {
        OperatorAccount::query()->findOrFail($id)->forceFill([
            'pending_secret_sealed' => null,
            'pending_secret_created_at' => null,
        ])->save();
    }

    public function enrol(string $id, string $secret, int $codeStep, DateTimeImmutable $at): void
    {
        OperatorAccount::query()->findOrFail($id)->forceFill([
            'secret_sealed' => $this->sealer->seal($secret, $id, 'secret'),
            'enrolled_at' => $at,
            'last_code_step' => $codeStep,
            'state' => OperatorState::Active->value,
            'pending_secret_sealed' => null,
            'pending_secret_created_at' => null,
        ])->save();
    }

    public function replaceSecret(string $id, string $secret, int $codeStep, DateTimeImmutable $at): void
    {
        OperatorAccount::query()->findOrFail($id)->forceFill([
            'secret_sealed' => $this->sealer->seal($secret, $id, 'secret'),
            'enrolled_at' => $at,
            'last_code_step' => $codeStep,
            'pending_secret_sealed' => null,
            'pending_secret_created_at' => null,
        ])->save();
    }

    public function recordCodeStep(string $id, int $step): void
    {
        OperatorAccount::query()->whereKey($id)->update(['last_code_step' => $step]);
    }

    public function markSignedIn(string $id, DateTimeImmutable $at): void
    {
        OperatorAccount::query()->findOrFail($id)->forceFill(['last_signed_in_at' => $at])->save();
    }

    public function clearFactor(string $id): void
    {
        $row = OperatorAccount::query()->findOrFail($id);
        $row->forceFill([
            'secret_sealed' => null,
            'enrolled_at' => null,
            'last_code_step' => null,
            'pending_secret_sealed' => null,
            'pending_secret_created_at' => null,
            'state' => OperatorState::AwaitingEnrolment->value,
            'credential_epoch' => $row->credential_epoch + 1,
        ])->save();
    }

    private function record(OperatorAccount $row): OperatorRecord
    {
        $id = $row->id;

        return new OperatorRecord(
            id: $id,
            emailLookup: $row->email_lookup,
            email: $this->sealer->open($row->email_sealed, $id, 'email'),
            displayName: $this->sealer->open($row->display_name_sealed, $id, 'display_name'),
            passwordHash: $row->password_hash,
            state: OperatorState::from($row->state),
            secret: $row->secret_sealed === null ? null : $this->sealer->open($row->secret_sealed, $id, 'secret'),
            pendingSecret: $row->pending_secret_sealed === null ? null : $this->sealer->open($row->pending_secret_sealed, $id, 'pending_secret'),
            pendingSecretCreatedAt: $row->pending_secret_created_at,
            lastCodeStep: $row->last_code_step,
            enrolledAt: $row->enrolled_at,
            credentialEpoch: $row->credential_epoch,
            temporaryPasswordExpiresAt: $row->temporary_password_expires_at,
            lastSignedInAt: $row->last_signed_in_at,
        );
    }
}
