<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An active viewer. Give `wallet_id` and `user_id` explicitly.
 *
 * @extends Factory<WalletMember>
 */
class WalletMemberFactory extends Factory
{
    protected $model = WalletMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => (string) Str::uuid7(),
            'role' => Role::Viewer->value,
            'version' => 1,
            'change_seq' => 2,
            'deleted_at' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => Role::Owner->value]);
    }

    public function editor(): static
    {
        return $this->state(fn (): array => ['role' => Role::Editor->value]);
    }

    public function viewer(): static
    {
        return $this->state(fn (): array => ['role' => Role::Viewer->value]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }
}
