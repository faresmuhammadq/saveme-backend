<?php

namespace App\Modules\Budgeting\Infrastructure\Database\Factories;

use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Identity\Application\Sync\UserScopeCounter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An active monthly budget for one category, 5,000.00 EGP. Give `user_id`
 * and `category_id` explicitly. States: `allExpenses`, `archived`, `deleted`.
 *
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'user_id' => (string) Str::uuid7(),
            'category_id' => (string) Str::uuid7(),
            'period_type' => 'monthly',
            'amount_minor' => 500000,
            'currency' => 'EGP',
            'archived_at' => null,
            'version' => 1,
            'change_seq' => fn (array $attributes): int => self::stampFor($attributes['user_id'] ?? null),
            'deleted_at' => null,
        ];
    }

    /**
     * The owner's next user-scope stamp; an owner with no users row takes a
     * stamp above anything a counter hands out.
     */
    private static function stampFor(?string $userId): int
    {
        static $orphan = 1_000_000_000;

        if ($userId !== null && DB::table('users')->where('id', $userId)->exists()) {
            return app(UserScopeCounter::class)->next($userId);
        }

        return ++$orphan;
    }

    public function allExpenses(): static
    {
        return $this->state(fn (): array => ['category_id' => null]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }
}
