<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person's active expense category. Give `user_id` explicitly, or the
 * category belongs to a person who does not exist. `->platform()` makes a
 * platform category instead.
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'user_id' => (string) Str::uuid7(),
            'system_key' => null,
            'kind' => CategoryKind::Expense->value,
            'name' => fake()->unique()->words(2, true),
            'names' => null,
            'icon' => null,
            'color' => null,
            'position' => null,
            'archived_at' => null,
            'version' => 1,
            'change_seq' => fn (): int => (int) DB::scalar("select nextval('sync_change_seq')"),
            'deleted_at' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (): array => ['kind' => CategoryKind::Income->value]);
    }

    public function expense(): static
    {
        return $this->state(fn (): array => ['kind' => CategoryKind::Expense->value]);
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
     * A platform category with a unique key, both names and the next free position.
     */
    public function platform(): static
    {
        return $this->state(function (): array {
            $key = 'test_'.Str::lower(Str::random(10));

            return [
                'user_id' => null,
                'name' => null,
                'system_key' => $key,
                'names' => ['ar' => 'اختبار '.$key, 'en' => 'Test '.$key],
                'icon' => 'dots',
                'color' => '#757575',
                'position' => (int) DB::scalar('select coalesce(max(position), 0) + 1 from categories where user_id is null'),
                'change_seq' => null,
            ];
        });
    }
}
