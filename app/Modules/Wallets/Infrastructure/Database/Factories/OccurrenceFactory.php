<?php

namespace App\Modules\Wallets\Infrastructure\Database\Factories;

use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A scheduled 4,500 EGP expense occurrence on 2026-11-01. Without a rule it
 * creates a rule and a wallet of its own, so name the rule with `forRule()`
 * to share its wallet. States: confirmed (with a linked transaction),
 * skipped, edited, tombstoned.
 *
 * @extends Factory<Occurrence>
 */
class OccurrenceFactory extends Factory
{
    protected $model = Occurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'wallet_id' => Wallet::factory(),
            'recurring_rule_id' => RecurringRule::factory(),
            'ordinal' => 1,
            'is_payout' => false,
            'scheduled_date' => '2026-11-01',
            'date' => '2026-11-01',
            'status' => 'scheduled',
            'kind' => 'expense',
            'amount_minor' => 450000,
            'currency' => 'EGP',
            'category_id' => Category::factory(),
            'counter_wallet_id' => null,
            'description' => 'Rent',
            'memo' => null,
            'counterparty' => null,
            'edited' => false,
            'transaction_id' => null,
            'auto_confirm_refusal' => null,
            'version' => 1,
            'change_seq' => 1,
            'deleted_at' => null,
        ];
    }

    /**
     * An occurrence of the rule, in the rule's wallet, with its template.
     */
    public function forRule(RecurringRule $rule): static
    {
        return $this->state(fn (): array => [
            'wallet_id' => $rule->wallet_id,
            'recurring_rule_id' => $rule->id,
            'kind' => $rule->kind,
            'amount_minor' => $rule->amount_minor,
            'currency' => $rule->currency,
            'category_id' => $rule->category_id,
            'counter_wallet_id' => $rule->counter_wallet_id,
            'description' => $rule->description,
        ]);
    }

    /**
     * Confirmed into a transaction of its own, linked from both sides.
     */
    public function confirmed(): static
    {
        return $this->state(fn (): array => ['status' => 'confirmed', 'transaction_id' => (string) Str::uuid7(), 'version' => 2])
            ->afterCreating(function (Occurrence $occurrence): void {
                Transaction::factory()->create([
                    'id' => $occurrence->transaction_id,
                    'wallet_id' => $occurrence->wallet_id,
                    'currency' => $occurrence->currency,
                    'category_id' => $occurrence->category_id,
                    'amount_minor' => $occurrence->amount_minor,
                    'date' => $occurrence->date,
                    'recurring_rule_id' => $occurrence->recurring_rule_id,
                    'occurrence_id' => $occurrence->id,
                ]);
            });
    }

    public function skipped(): static
    {
        return $this->state(fn (): array => ['status' => 'skipped', 'version' => 2]);
    }

    public function edited(): static
    {
        return $this->state(fn (): array => ['edited' => true, 'amount_minor' => 61200, 'version' => 2]);
    }

    public function tombstoned(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now(), 'version' => 2]);
    }
}
