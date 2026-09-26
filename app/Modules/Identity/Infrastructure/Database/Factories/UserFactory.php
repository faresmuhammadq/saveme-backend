<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories;

use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A user with a sealed address, its lookup value and its data key. The
 * password is `password` unless given. Use `withEmail()` when a test needs
 * to sign in with a known address.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $defaultHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['id' => (string) Str::uuid7()] + $this->addressAttributes(fake()->unique()->safeEmail()) + [
            'password_hash' => self::$defaultHash ??= Hash::make('password'),
            'state' => AccountState::Active->value,
            'email_verified_at' => null,
        ];
    }

    public function withEmail(string $email): static
    {
        return $this->state(fn (): array => $this->addressAttributes($email));
    }

    public function withPassword(string $password): static
    {
        return $this->state(fn (): array => ['password_hash' => Hash::make($password)]);
    }

    public function verified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function pendingDeletion(): static
    {
        return $this->state(fn (): array => [
            'state' => AccountState::PendingDeletion->value,
            'deletion_requested_at' => now(),
            'deletion_scheduled_for' => now()->addDays((int) config('identity.deletion_grace_days')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressAttributes(string $email): array
    {
        return [
            'email_lookup' => app(AddressLookup::class)->for($email),
            'email_ciphertext' => function (array $attributes) use ($email): string {
                $cipher = app(AttributeCipher::class);

                if (UserKey::query()->find($attributes['id']) === null) {
                    $cipher->newDataKeyFor($attributes['id']);
                }

                return $cipher->seal($attributes['id'], $email);
            },
        ];
    }
}
