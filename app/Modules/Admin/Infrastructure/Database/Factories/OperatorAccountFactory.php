<?php

namespace App\Modules\Admin\Infrastructure\Database\Factories;

use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\RecoveryCodeHasher;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Domain\Ports\SecretSealer;
use App\Modules\Admin\Domain\SecondFactor\RecoveryCode;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * An Operator Account with sealed email and display name, found by its
 * lookup value; sealed through the real AdminSealer (tests only). The
 * default is an operator awaiting enrolment whose password is `password`.
 * Use the states to reach the others.
 *
 * @extends Factory<OperatorAccount>
 */
class OperatorAccountFactory extends Factory
{
    protected $model = OperatorAccount::class;

    private static ?string $defaultHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid7();

        return ['id' => $id]
            + $this->identityAttributes($id, fake()->unique()->safeEmail(), fake()->name())
            + [
                'password_hash' => self::$defaultHash ??= Hash::make('password'),
                'state' => OperatorState::AwaitingEnrolment->value,
                'credential_epoch' => 0,
            ];
    }

    public function withEmail(string $email): static
    {
        return $this->state(fn (array $attributes): array => $this->identityAttributes(
            $attributes['id'],
            $email,
            app(SecretSealer::class)->open($attributes['display_name_sealed'], $attributes['id'], 'display_name'),
        ));
    }

    public function withDisplayName(string $name): static
    {
        return $this->state(fn (array $attributes): array => $this->identityAttributes(
            $attributes['id'],
            app(SecretSealer::class)->open($attributes['email_sealed'], $attributes['id'], 'email'),
            $name,
        ));
    }

    public function withPassword(string $password): static
    {
        return $this->state(fn (): array => ['password_hash' => Hash::make($password)]);
    }

    public function awaitingPasswordChange(string $temporaryPassword): static
    {
        return $this->state(fn (): array => [
            'password_hash' => Hash::make($temporaryPassword),
            'state' => OperatorState::AwaitingPasswordChange->value,
            'temporary_password_expires_at' => now()->addHours((int) config('admin.auth.temporary_password_hours')),
        ]);
    }

    public function awaitingEnrolment(): static
    {
        return $this->state(fn (): array => ['state' => OperatorState::AwaitingEnrolment->value]);
    }

    /**
     * @param  string|null  $secret  the raw 20-byte secret; random when omitted
     */
    public function enrolled(?string $secret = null): static
    {
        $secret ??= random_bytes(20);

        return $this->state(fn (array $attributes): array => [
            'state' => OperatorState::Active->value,
            'secret_sealed' => app(SecretSealer::class)->seal($secret, $attributes['id'], 'secret'),
            'enrolled_at' => now(),
            'password_changed_at' => now(),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => ['state' => OperatorState::Deactivated->value]);
    }

    /**
     * Stores a set of recovery codes.
     *
     * @param  list<string>  $plain  normalised codes
     */
    public function withRecoveryCodes(array $plain): static
    {
        return $this->afterCreating(function (OperatorAccount $operator) use ($plain): void {
            $hasher = app(RecoveryCodeHasher::class);

            app(RecoveryCodeStore::class)->replaceSet(
                $operator->id,
                array_map(fn (string $code): string => $hasher->hash((string) RecoveryCode::normalise($code)), $plain),
            );
        });
    }

    /**
     * @return array<string, string>
     */
    private function identityAttributes(string $id, string $email, string $displayName): array
    {
        $sealer = app(SecretSealer::class);

        return [
            'email_lookup' => app(EmailLookup::class)->lookup($email),
            'email_sealed' => $sealer->seal($email, $id, 'email'),
            'display_name_sealed' => $sealer->seal($displayName, $id, 'display_name'),
        ];
    }
}
