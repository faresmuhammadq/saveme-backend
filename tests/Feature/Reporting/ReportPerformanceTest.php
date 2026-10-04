<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * SC-005 (spec 019): wallet summary, by-category and a 6-period trend over one
 * wallet with 10,000 transactions p95 < 300 ms; the three cross-wallet reports
 * over 20 wallets and 20,000 transactions p95 < 500 ms. Slow to seed, so it
 * runs only on request, with Xdebug off and the tables analysed:
 *
 *   REPORTS_PERF=1 XDEBUG_MODE=off vendor/bin/pest tests/Feature/Reporting/ReportPerformanceTest.php
 *
 * REPORTS_PERF_NO_INDEX=1 drops postings_wallet_leg_index inside the test
 * transaction first, to measure without it (research R-3, A-20).
 */

function reportPercentile95(array $milliseconds): float
{
    sort($milliseconds);

    return $milliseconds[(int) ceil(0.95 * count($milliseconds)) - 1];
}

function reportTimed(Closure $call, int $runs = 40): array
{
    $call();
    $samples = [];

    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $call();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }

    return $samples;
}

/**
 * Seeds the transactions of the wallets with bulk inserts: 80% expenses, 15%
 * incomes, 5% transfers between the wallets, over two years to 2026-10-04.
 *
 * @param  list<string>  $walletIds
 */
function seedReportLedger(string $userId, array $walletIds, int $count): void
{
    $expense = Category::query()->whereNull('user_id')->where('kind', 'expense')->pluck('id')->all();
    $income = Category::query()->whereNull('user_id')->where('kind', 'income')->pluck('id')->all();
    $now = now()->format('Y-m-d H:i:s.uP');
    $seq = 1_000_000;
    $wallets = count($walletIds);

    foreach (array_chunk(range(1, $count), 1000) as $chunk) {
        $transactions = [];
        $postings = [];

        foreach ($chunk as $n) {
            $id = (string) Str::uuid7();
            $wallet = $walletIds[$n % $wallets];
            $amount = 100 + ($n * 37) % 90000;
            $date = (new DateTimeImmutable('2024-10-05'))->modify('+'.($n % 730).' days')->format('Y-m-d');
            $kind = $n % 20 === 0 && $wallets > 1 ? 'transfer' : ($n % 7 === 0 ? 'income' : 'expense');
            $counter = $kind === 'transfer' ? $walletIds[($n + 1) % $wallets] : null;
            $category = $kind === 'transfer' ? null : ($kind === 'income' ? $income[$n % count($income)] : $expense[$n % count($expense)]);

            $transactions[] = [
                'id' => $id, 'wallet_id' => $wallet, 'kind' => $kind, 'date' => $date, 'amount_minor' => $amount, 'currency' => 'EGP',
                'category_id' => $category, 'counter_wallet_id' => $counter, 'cleared' => false, 'created_by' => $userId, 'version' => 1,
                'change_seq' => ++$seq, 'counter_change_seq' => $counter === null ? null : $seq, 'created_at' => $now, 'updated_at' => $now,
            ];

            $sign = $kind === 'income' ? 1 : -1;
            $postings[] = ['id' => (string) Str::uuid7(), 'transaction_id' => $id, 'wallet_id' => $wallet, 'category_id' => null, 'amount_minor' => $sign * $amount, 'currency' => 'EGP'];
            $postings[] = $kind === 'transfer'
                ? ['id' => (string) Str::uuid7(), 'transaction_id' => $id, 'wallet_id' => $counter, 'category_id' => null, 'amount_minor' => $amount, 'currency' => 'EGP']
                : ['id' => (string) Str::uuid7(), 'transaction_id' => $id, 'wallet_id' => null, 'category_id' => $category, 'amount_minor' => -$sign * $amount, 'currency' => 'EGP'];
        }

        DB::table('transactions')->insert($transactions);
        DB::table('postings')->insert($postings);
    }
}

test('the six reports stay inside the SC-005 targets', function () {
    freezeClock('2026-10-04 10:00:00');

    if (getenv('REPORTS_PERF_NO_INDEX') === '1') {
        DB::statement('DROP INDEX IF EXISTS postings_wallet_leg_index');
    }

    [$single, $singleCredential] = walletCaller('single@example.test');
    $big = ownedWallet($single->id, ['opening_balance_minor' => 100000]);
    seedReportLedger($single->id, [$big->id], 10000);

    [$many, $manyCredential] = walletCaller('many@example.test');
    $twenty = array_map(fn () => ownedWallet($many->id, ['opening_balance_minor' => 5000])->id, range(1, 20));
    seedReportLedger($many->id, $twenty, 20000);

    foreach (['transactions', 'postings', 'wallets', 'wallet_members', 'categories', 'occurrences'] as $table) {
        DB::statement('ANALYZE '.$table);
    }

    $get = fn (string $credential, string $uri) => fn () => reportCall($credential, $uri)->assertOk();
    $wallet = '/wallets/'.$big->id.'/reports';

    $results = [
        'wallet summary' => reportTimed($get($singleCredential, $wallet.'/summary')),
        'wallet by-category' => reportTimed($get($singleCredential, $wallet.'/by-category')),
        'wallet trend (6)' => reportTimed($get($singleCredential, $wallet.'/trend')),
        'cross-wallet summary' => reportTimed($get($manyCredential, '/reports/summary')),
        'cross-wallet by-category' => reportTimed($get($manyCredential, '/reports/by-category')),
        'cross-wallet trend (6)' => reportTimed($get($manyCredential, '/reports/trend')),
    ];

    $lines = ["\nSC-005 ".(getenv('REPORTS_PERF_NO_INDEX') === '1' ? 'WITHOUT' : 'WITH').' postings_wallet_leg_index (ms):'];
    foreach ($results as $name => $samples) {
        sort($samples);
        $lines[] = sprintf('  %-26s p95 %7.1f  median %7.1f', $name, reportPercentile95($samples), $samples[(int) (count($samples) / 2)]);
    }
    fwrite(STDOUT, implode("\n", $lines)."\n");

    foreach ($results as $name => $samples) {
        expect(reportPercentile95($samples))->toBeLessThan(str_starts_with($name, 'cross') ? 500 : 300, $name);
    }
})->skip(fn () => getenv('REPORTS_PERF') !== '1', 'Set REPORTS_PERF=1 to measure SC-005.');
