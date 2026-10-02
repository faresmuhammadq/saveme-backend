<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Domain\Transactions\PostingDerivation;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentPostingRepository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A live expense of 1,000 minor units in an EGP wallet, with its postings.
 * Without explicit `wallet_id` and `category_id` it creates a wallet and a
 * category of its own; the balance cache of the wallet is not touched, so a
 * test that asserts a balance recomputes it (WalletRepository::refreshBalance).
 * States: expense, income, transfer, tombstoned. A tombstone has no postings.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'wallet_id' => Wallet::factory(),
            'kind' => TransactionKind::Expense->value,
            'date' => '2026-10-01',
            'amount_minor' => 1000,
            'currency' => 'EGP',
            'category_id' => Category::factory(),
            'counter_wallet_id' => null,
            'description' => null,
            'memo' => null,
            'counterparty' => null,
            'cleared' => false,
            'created_by' => (string) Str::uuid7(),
            'version' => 1,
            'change_seq' => 1,
            'counter_change_seq' => null,
            'deleted_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Transaction $transaction): void {
            if ($transaction->deleted_at !== null) {
                return;
            }

            app(EloquentPostingRepository::class)->replaceFor($transaction->id, PostingDerivation::for($transaction->toRecord()));
        });
    }

    public function expense(): static
    {
        return $this->state(fn (): array => ['kind' => TransactionKind::Expense->value]);
    }

    public function income(): static
    {
        return $this->state(fn (): array => ['kind' => TransactionKind::Income->value, 'category_id' => Category::factory()->income()]);
    }

    /**
     * Between the wallet and a second one, with the destination's stamp.
     */
    public function transfer(): static
    {
        return $this->state(fn (): array => [
            'kind' => TransactionKind::Transfer->value,
            'category_id' => null,
            'counter_wallet_id' => Wallet::factory(),
            'counter_change_seq' => 1,
        ]);
    }

    public function tombstoned(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now(), 'version' => 2]);
    }
}
