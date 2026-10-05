<?php

use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * SC-001, opt-in (EXPORT_PERF=1): an account export of 10,000 transactions
 * across 10 wallets is ready within two minutes, and memory stays flat.
 * Skipped in the ordinary suite: it seeds ten thousand ledger rows.
 */

test('an account of 10,000 transactions over 10 wallets exports in under two minutes', function () {
    Storage::fake('local');
    $user = namedPerson('perf@example.test', 'Perf Person');
    $categoryId = platformCategoryId();
    $now = now()->format('Y-m-d H:i:s.uP');

    foreach (range(1, 10) as $n) {
        $wallet = ownedWallet($user->id, ['name' => "Wallet {$n}"]);
        $rows = [];

        foreach (range(1, 1000) as $i) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'wallet_id' => $wallet->id, 'kind' => 'expense', 'date' => '2026-10-01', 'amount_minor' => 100 + $i,
                'currency' => 'EGP', 'category_id' => $categoryId, 'description' => "Entry {$i}", 'cleared' => false, 'created_by' => $user->id,
                'version' => 1, 'change_seq' => $i + 5, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
    }

    $export = DataExport::factory()->create(['user_id' => $user->id]);
    $before = memory_get_peak_usage(true);
    $started = microtime(true);

    app(GenerateExport::class)->run($export->id);

    $seconds = microtime(true) - $started;
    $peakMegabytes = (memory_get_peak_usage(true) - $before) / 1048576;
    fwrite(STDERR, sprintf("\nexport of 10,000 transactions: %.1f s, peak memory growth %.1f MB, archive %d bytes\n", $seconds, $peakMegabytes, DataExport::query()->find($export->id)->size_bytes));

    expect(DataExport::query()->find($export->id)->status)->toBe('ready')
        ->and($seconds)->toBeLessThan(120.0);
})->skip(fn (): bool => ! (bool) getenv('EXPORT_PERF'), 'Opt-in: set EXPORT_PERF=1 (SC-001).');
