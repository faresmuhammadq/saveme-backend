<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A live reference. Give `wallet_id`, `category_id` and `owner_user_id`
 * explicitly.
 *
 * @extends Factory<CategoryReference>
 */
class CategoryReferenceFactory extends Factory
{
    protected $model = CategoryReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => (string) Str::uuid7(),
            'kind' => CategoryKind::Expense->value,
            'name' => fake()->words(2, true),
            'icon' => null,
            'color' => null,
            'version' => 1,
            'change_seq' => 2,
            'deleted_at' => null,
        ];
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }
}
