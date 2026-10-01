<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Domain\Wallets\WalletType;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An active EGP cash wallet with a zero balance. It has no member: pair it
 * with WalletMemberFactory, or use `->ownedBy($userId)` for the common case.
 *
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'type' => WalletType::Cash->value,
            'name' => fake()->words(2, true),
            'currency' => 'EGP',
            'opening_balance_minor' => 0,
            'balance_minor' => 0,
            'icon' => null,
            'color' => null,
            'archived_at' => null,
            'version' => 1,
            'change_seq' => 1,
            'deleted_at' => null,
        ];
    }

    public function ofType(WalletType $type): static
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    public function withOpeningBalance(int $minor): static
    {
        return $this->state(fn (): array => ['opening_balance_minor' => $minor, 'balance_minor' => $minor]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }

    /**
     * Adds the owner membership after the wallet is created. The wallet's
     * change_seq ends above the membership's, as a real create leaves it.
     */
    public function ownedBy(string $userId): static
    {
        return $this->afterCreating(function (Wallet $wallet) use ($userId): void {
            $wallet->forceFill(['change_seq' => 3])->save();
            WalletMemberFactory::new()->owner()->create(['wallet_id' => $wallet->id, 'user_id' => $userId, 'change_seq' => 2]);
        });
    }
}
