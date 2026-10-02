<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * SC-005 (spec 015): 50 budgets over 20,000 of the owner's in-period expenses.
 * Progress list p95 < 300 ms; budget list and read p95 < 150 ms. Slow to seed,
 * so it runs only on request: `BUDGET_PERF=1 vendor/bin/pest <this file>`.
 */

function budgetPercentile95(array $milliseconds): float
{
    sort($milliseconds);

    return $milliseconds[(int) ceil(0.95 * count($milliseconds)) - 1];
}

function budgetTimed(Closure $call, int $runs = 40): array
{
    $samples = [];

    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $call();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }

    return $samples;
}

test('the progress list over 50 budgets and 20,000 expenses stays inside the targets', function () {
    freezeClock('2026-10-15 10:00:00');
    [$owner, $credential] = walletCaller();
    $wallet = ownedWallet($owner->id);

    $platform = Category::query()->whereNull('user_id')->where('kind', 'expense')->orderBy('position')->pluck('id')->all();
    $own = [];
    foreach (range(1, 25) as $_) {
        $own[] = ownedCategory($owner->id)->id;
    }
    $categories = [...$platform, ...$own];

    foreach (range(1, 50) as $i) {
        createBudget($owner->id, ['category_id' => $i <= 5 ? null : $categories[$i % count($categories)], 'amount_minor' => 1000000 + $i]);
    }

    $now = now()->format('Y-m-d H:i:s.uP');
    $seq = 1_000_000;
    foreach (array_chunk(range(1, 20000), 1000) as $chunk) {
        $transactions = [];
        $postings = [];
        foreach ($chunk as $n) {
            $id = (string) Str::uuid7();
            $category = $categories[$n % count($categories)];
            $transactions[] = [
                'id' => $id, 'wallet_id' => $wallet->id, 'kind' => 'expense', 'date' => '2026-10-'.str_pad((string) (1 + $n % 28), 2, '0', STR_PAD_LEFT),
                'amount_minor' => 100 + $n % 5000, 'currency' => 'EGP', 'category_id' => $category, 'cleared' => false, 'created_by' => $owner->id,
                'version' => 1, 'change_seq' => ++$seq, 'created_at' => $now, 'updated_at' => $now,
            ];
            $postings[] = ['id' => (string) Str::uuid7(), 'transaction_id' => $id, 'wallet_id' => $wallet->id, 'category_id' => null, 'amount_minor' => -(100 + $n % 5000), 'currency' => 'EGP'];
            $postings[] = ['id' => (string) Str::uuid7(), 'transaction_id' => $id, 'wallet_id' => null, 'category_id' => $category, 'amount_minor' => 100 + $n % 5000, 'currency' => 'EGP'];
        }
        DB::table('transactions')->insert($transactions);
        DB::table('postings')->insert($postings);
    }

    DB::statement('ANALYZE transactions');
    DB::statement('ANALYZE postings');
    DB::statement('ANALYZE budgets');
    DB::statement('ANALYZE wallets');
    DB::statement('ANALYZE wallet_members');

    $call = fn (string $uri) => fn () => budgetCall('GET', $credential, $uri)->assertOk();
    $call('/budget-progress?limit=50')();

    $progress = budgetTimed($call('/budget-progress?limit=50'));
    $list = budgetTimed($call('/budgets?limit=50'));
    $one = budgetTimed($call('/budgets/'.DB::table('budgets')->value('id')));

    $spendingSql = "SELECT p.category_id, p.currency, SUM(p.amount_minor)::bigint FROM transactions t JOIN postings p ON p.transaction_id = t.id AND p.category_id IS NOT NULL JOIN wallets w ON w.id = t.wallet_id AND w.deleted_at IS NULL JOIN wallet_members m ON m.wallet_id = t.wallet_id AND m.user_id = '{$owner->id}' AND m.deleted_at IS NULL WHERE t.created_by = '{$owner->id}' AND t.deleted_at IS NULL AND t.kind = 'expense' AND t.date BETWEEN '2026-10-01' AND '2026-10-31' GROUP BY p.category_id, p.currency";
    $plan = implode("\n", array_map(fn ($row) => $row->{'QUERY PLAN'}, DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$spendingSql)));

    fwrite(STDOUT, sprintf(
        "\nSC-005 over 50 budgets / 20000 expenses (ms): progress p95 %.1f (median %.1f), budgets list p95 %.1f, budget read p95 %.1f\n%s\n",
        budgetPercentile95($progress), $progress[(int) (count($progress) / 2)], budgetPercentile95($list), budgetPercentile95($one), $plan,
    ));

    expect(budgetPercentile95($progress))->toBeLessThan(300)
        ->and(budgetPercentile95($list))->toBeLessThan(150)
        ->and(budgetPercentile95($one))->toBeLessThan(150);
})->skip(fn () => getenv('BUDGET_PERF') !== '1', 'Set BUDGET_PERF=1 to measure SC-005.');
