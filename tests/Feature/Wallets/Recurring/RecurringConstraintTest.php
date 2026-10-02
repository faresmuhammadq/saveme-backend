<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The shape of rules and occurrences is held by the database as well as the
 * code (spec 014 data-model; P-03, P-06, ADR-0018 rules 2 and 5): each CHECK
 * and each unique index fails for a violating row; valid rows insert.
 */

beforeEach(function () {
    $this->wallet = Wallet::factory()->create();
    $this->other = Wallet::factory()->create();
    $this->category = Category::factory()->create();
    $this->income = Category::factory()->income()->create();
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function ruleRow(string $walletId, string $categoryId, array $changes = []): array
{
    return [
        'id' => (string) Str::uuid7(), 'wallet_id' => $walletId, 'preset' => 'custom', 'status' => 'active', 'kind' => 'expense',
        'amount_minor' => 1000, 'amount_basis' => 'per_occurrence', 'currency' => 'EGP', 'category_id' => $categoryId, 'counter_wallet_id' => null,
        'description' => null, 'memo' => null, 'counterparty' => null, 'auto_confirm' => false, 'auto_confirm_from' => '2026-10-01',
        'frequency' => 'monthly', 'interval' => 1, 'anchor_day' => 1, 'start_date' => '2026-11-01', 'end_date' => null, 'occurrence_count' => null,
        'payout_round' => null, 'payout_category_id' => null, 'timezone' => 'Africa/Cairo', 'created_by' => (string) Str::uuid7(),
        'version' => 1, 'change_seq' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ...$changes,
    ];
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function occurrenceRow(string $walletId, string $ruleId, ?string $categoryId, array $changes = []): array
{
    return [
        'id' => (string) Str::uuid7(), 'wallet_id' => $walletId, 'recurring_rule_id' => $ruleId, 'ordinal' => 1, 'is_payout' => false,
        'scheduled_date' => '2026-11-01', 'date' => '2026-11-01', 'status' => 'scheduled', 'kind' => 'expense', 'amount_minor' => 1000,
        'currency' => 'EGP', 'category_id' => $categoryId, 'counter_wallet_id' => null, 'description' => null, 'memo' => null, 'counterparty' => null,
        'edited' => false, 'transaction_id' => null, 'auto_confirm_refusal' => null, 'version' => 1, 'change_seq' => 1,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ...$changes,
    ];
}

/**
 * Whether the insert fails with the SQLSTATE: 23514 a CHECK, 23505 a unique index.
 */
function failsWith(string $state, Closure $insert): bool
{
    try {
        DB::transaction($insert);
    } catch (QueryException $exception) {
        return $exception->getCode() === $state;
    }

    return false;
}

test('valid rows insert', function () {
    $rule = ruleRow($this->wallet->id, $this->category->id);
    $gameya = ruleRow($this->wallet->id, $this->category->id, ['preset' => 'gameya', 'frequency' => 'monthly', 'occurrence_count' => 10, 'payout_round' => 4, 'payout_category_id' => $this->income->id]);
    $transfer = ruleRow($this->wallet->id, $this->category->id, ['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $this->other->id]);
    $daily = ruleRow($this->wallet->id, $this->category->id, ['frequency' => 'daily', 'anchor_day' => null, 'end_date' => '2026-12-31']);
    DB::table('recurring_rules')->insert([$rule, $gameya, $transfer, $daily]);
    DB::table('occurrences')->insert([occurrenceRow($this->wallet->id, $rule['id'], $this->category->id), occurrenceRow($this->wallet->id, $rule['id'], $this->category->id, ['is_payout' => true])]);

    expect(RecurringRule::query()->count())->toBe(4)->and(Occurrence::query()->count())->toBe(2);
});

test('a rule row that breaks a CHECK fails at the database', function (string $case, array $changes) {
    expect(failsWith('23514', fn () => DB::table('recurring_rules')->insert(ruleRow($this->wallet->id, $this->category->id, $changes))))->toBeTrue($case);
})->with([
    'unknown preset' => ['preset', ['preset' => 'savings']],
    'unknown status' => ['status', ['status' => 'done']],
    'unknown kind' => ['kind', ['kind' => 'refund']],
    'zero amount' => ['amount', ['amount_minor' => 0]],
    'amount above 2^53 - 1' => ['amount', ['amount_minor' => 9007199254740992]],
    'unknown basis' => ['basis', ['amount_basis' => 'half']],
    'expense without a category' => ['category shape', ['category_id' => null]],
    'a transfer with a category' => ['transfer category', ['kind' => 'transfer', 'counter_wallet_id' => '00000000-0000-7000-8000-000000000000']],
    'an expense with a destination' => ['counter shape', ['counter_wallet_id' => '00000000-0000-7000-8000-000000000001']],
    'blank description' => ['description', ['description' => '   ']],
    'unknown frequency' => ['frequency', ['frequency' => 'hourly']],
    'interval 0' => ['interval', ['interval' => 0]],
    'interval 366' => ['interval', ['interval' => 366]],
    'anchor 32' => ['anchor', ['anchor_day' => 32]],
    'monthly without an anchor' => ['anchor shape', ['anchor_day' => null]],
    'daily with an anchor' => ['anchor shape', ['frequency' => 'daily']],
    'start before 1900' => ['start', ['start_date' => '1899-12-31']],
    'end before start' => ['end', ['end_date' => '2026-10-31']],
    'count 0' => ['count', ['occurrence_count' => 0]],
    'count 1001' => ['count', ['occurrence_count' => 1001]],
    'both end members' => ['one end', ['end_date' => '2027-01-01', 'occurrence_count' => 3]],
    'payout members on a custom rule' => ['payout shape', ['payout_round' => 1, 'occurrence_count' => 3]],
    'a gam\'eya without payout members' => ['payout shape', ['preset' => 'gameya', 'occurrence_count' => 3]],
    'payout round beyond the count' => ['payout range', ['preset' => 'gameya', 'occurrence_count' => 3, 'payout_round' => 4, 'payout_category_id' => '00000000-0000-7000-8000-000000000002']],
    'version 0' => ['version', ['version' => 0]],
]);

test('an occurrence row that breaks a CHECK fails at the database', function (string $case, array $changes) {
    $rule = ruleRow($this->wallet->id, $this->category->id);
    DB::table('recurring_rules')->insert($rule);

    expect(failsWith('23514', fn () => DB::table('occurrences')->insert(occurrenceRow($this->wallet->id, $rule['id'], $this->category->id, $changes))))->toBeTrue($case);
})->with([
    'unknown status' => ['status', ['status' => 'due']],
    'zero amount' => ['amount', ['amount_minor' => 0]],
    'date out of range' => ['date', ['date' => '3000-01-01']],
    'confirmed without a transaction' => ['confirmed shape', ['status' => 'confirmed']],
    'a transaction on a scheduled occurrence' => ['transaction shape', ['transaction_id' => '00000000-0000-7000-8000-000000000003']],
    'unknown refusal' => ['refusal', ['auto_confirm_refusal' => 'tired']],
    'ordinal 0' => ['ordinal', ['ordinal' => 0]],
    'version 0' => ['version', ['version' => 0]],
]);

test('the occurrence key is unique over live and tombstoned rows, a payout having its own', function () {
    $rule = ruleRow($this->wallet->id, $this->category->id);
    DB::table('recurring_rules')->insert($rule);
    DB::table('occurrences')->insert(occurrenceRow($this->wallet->id, $rule['id'], $this->category->id, ['deleted_at' => now()]));

    expect(failsWith('23505', fn () => DB::table('occurrences')->insert(occurrenceRow($this->wallet->id, $rule['id'], $this->category->id))))->toBeTrue();

    DB::table('occurrences')->insert(occurrenceRow($this->wallet->id, $rule['id'], $this->category->id, ['is_payout' => true]));

    expect(Occurrence::query()->count())->toBe(2);
});

test('two occurrences cannot hold one transaction id', function () {
    $rule = ruleRow($this->wallet->id, $this->category->id);
    DB::table('recurring_rules')->insert($rule);
    $transactionId = (string) Str::uuid7();
    DB::table('occurrences')->insert(occurrenceRow($this->wallet->id, $rule['id'], $this->category->id, ['status' => 'confirmed', 'transaction_id' => $transactionId]));

    expect(failsWith('23505', fn () => DB::table('occurrences')->insert(occurrenceRow($this->wallet->id, $rule['id'], $this->category->id, ['status' => 'confirmed', 'transaction_id' => $transactionId, 'scheduled_date' => '2026-12-01']))))->toBeTrue();
});

test('two transactions cannot name one occurrence, and the links go together', function () {
    $rule = ruleRow($this->wallet->id, $this->category->id);
    DB::table('recurring_rules')->insert($rule);
    $occurrence = occurrenceRow($this->wallet->id, $rule['id'], $this->category->id);
    DB::table('occurrences')->insert($occurrence);

    Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'recurring_rule_id' => $rule['id'], 'occurrence_id' => $occurrence['id']]);

    expect(failsWith('23505', fn () => Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'recurring_rule_id' => $rule['id'], 'occurrence_id' => $occurrence['id']])))->toBeTrue()
        ->and(failsWith('23514', fn () => Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'recurring_rule_id' => $rule['id'], 'occurrence_id' => null])))->toBeTrue()
        ->and(failsWith('23514', fn () => Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $this->category->id, 'recurring_rule_id' => null, 'occurrence_id' => $occurrence['id']])))->toBeTrue();
});
