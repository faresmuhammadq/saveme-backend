<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An active, automatic monthly expense rule of 4,500 EGP on the 1st, in a
 * wallet and with a category of its own unless given. It materialises
 * nothing; states: monthly, daily, transfer, gameya, installment, paused,
 * ended, tombstoned.
 *
 * @extends Factory<RecurringRule>
 */
class RecurringRuleFactory extends Factory
{
    protected $model = RecurringRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'wallet_id' => Wallet::factory(),
            'preset' => 'custom',
            'status' => RuleStatus::Active->value,
            'kind' => 'expense',
            'amount_minor' => 450000,
            'amount_basis' => 'per_occurrence',
            'currency' => 'EGP',
            'category_id' => Category::factory(),
            'counter_wallet_id' => null,
            'description' => 'Rent',
            'memo' => null,
            'counterparty' => null,
            'auto_confirm' => false,
            'auto_confirm_from' => '2026-10-01',
            'frequency' => 'monthly',
            'interval' => 1,
            'anchor_day' => 1,
            'start_date' => '2026-11-01',
            'end_date' => null,
            'occurrence_count' => null,
            'payout_round' => null,
            'payout_category_id' => null,
            'timezone' => 'Africa/Cairo',
            'created_by' => (string) Str::uuid7(),
            'version' => 1,
            'change_seq' => 1,
            'deleted_at' => null,
        ];
    }

    public function monthly(int $anchorDay = 1): static
    {
        return $this->state(fn (): array => ['frequency' => 'monthly', 'interval' => 1, 'anchor_day' => $anchorDay]);
    }

    public function daily(): static
    {
        return $this->state(fn (): array => ['frequency' => 'daily', 'interval' => 1, 'anchor_day' => null]);
    }

    public function automatic(): static
    {
        return $this->state(fn (): array => ['auto_confirm' => true]);
    }

    /**
     * Between the wallet and a second one.
     */
    public function transfer(): static
    {
        return $this->state(fn (): array => [
            'kind' => 'transfer',
            'category_id' => null,
            'counter_wallet_id' => Wallet::factory(),
        ]);
    }

    /**
     * Ten monthly contributions, the payout in round 4.
     */
    public function gameya(): static
    {
        return $this->state(fn (): array => [
            'preset' => 'gameya',
            'amount_minor' => 100000,
            'occurrence_count' => 10,
            'payout_round' => 4,
            'payout_category_id' => Category::factory()->income(),
        ]);
    }

    /**
     * 10,000 EGP over 12 monthly installments.
     */
    public function installment(): static
    {
        return $this->state(fn (): array => [
            'preset' => 'installment',
            'amount_basis' => 'total',
            'amount_minor' => 1000000,
            'occurrence_count' => 12,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => RuleStatus::Paused->value]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => ['status' => RuleStatus::Ended->value]);
    }

    public function tombstoned(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now(), 'version' => 2]);
    }
}
