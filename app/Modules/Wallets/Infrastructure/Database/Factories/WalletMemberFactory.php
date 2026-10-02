<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
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
            'member_change_seq' => fn (array $attributes): int => self::stampFor($attributes['user_id']),
            'deleted_at' => null,
        ];
    }

    /**
     * The member's next user-scope stamp; a member with no users row (many
     * tests have none) takes a stamp above anything a counter hands out.
     */
    private static function stampFor(string $userId): int
    {
        static $orphan = 1_000_000_000;

        if (DB::table('users')->where('id', $userId)->exists()) {
            return app(UserScopeSequence::class)->next($userId);
        }

        return ++$orphan;
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
