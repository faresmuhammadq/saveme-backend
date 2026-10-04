<?php

use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Domain\ReportRange;
use App\Modules\Wallets\Application\Reports\LedgerReportQuery;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * One snapshot per report (spec 019, FR-017a, ADR-0022 rule 7): the reads of a
 * report see one committed state even when another session commits between
 * them. Two sessions cannot see each other's uncommitted rows, so this test
 * commits the transaction RefreshDatabase opened, works with committed rows
 * and removes them afterwards, as the lock-order tests do.
 */

function insertExpense(string $connection, string $walletId, string $userId, int $amount, string $date): void
{
    $id = (string) Str::uuid7();
    $db = DB::connection($connection);
    $category = $db->table('categories')->whereNull('user_id')->where('kind', 'expense')->value('id');

    $db->table('transactions')->insert([
        'id' => $id, 'wallet_id' => $walletId, 'kind' => 'expense', 'date' => $date, 'amount_minor' => $amount,
        'currency' => 'EGP', 'category_id' => $category, 'created_by' => $userId, 'version' => 1, 'change_seq' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $db->table('postings')->insert([
        ['id' => (string) Str::uuid7(), 'transaction_id' => $id, 'wallet_id' => $walletId, 'category_id' => null, 'amount_minor' => -$amount, 'currency' => 'EGP'],
        ['id' => (string) Str::uuid7(), 'transaction_id' => $id, 'wallet_id' => null, 'category_id' => $category, 'amount_minor' => $amount, 'currency' => 'EGP'],
    ]);
}

beforeEach(function () {
    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 1000]);
    insertExpense('pgsql', $this->wallet->id, $this->owner->id, 100, '2026-10-02');
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::table('postings')->delete();
    DB::table('transactions')->delete();
    DB::table('wallet_members')->delete();
    DB::table('wallets')->delete();
    DB::table('profiles')->delete();
    DB::table('users')->delete();
    DB::purge('pgsql_b');
});

test('a commit between the reads of one snapshot is invisible to the rest of it, and visible to the next report', function () {
    $ledger = app(LedgerReportQuery::class);
    $ids = [$this->wallet->id];

    $seen = $ledger->snapshot(function () use ($ledger, $ids): array {
        $first = $ledger->walletLegTotals($ids, '2026-10-01', '2026-10-31');

        insertExpense('pgsql_b', $this->wallet->id, $this->owner->id, 250, '2026-10-03');

        return [
            'first' => $first[0]->amountMinor,
            'second' => $ledger->walletLegTotals($ids, '2026-10-01', '2026-10-31')[0]->amountMinor,
            'categories' => $ledger->categoryTotals($ids, '2026-10-01', '2026-10-31')[0]->amountMinor,
            'before' => $ledger->balancesBefore($ids, '2026-11-01')[$this->wallet->id],
        ];
    });

    expect($seen)->toBe(['first' => -100, 'second' => -100, 'categories' => 100, 'before' => -100])
        ->and($ledger->walletLegTotals($ids, '2026-10-01', '2026-10-31')[0]->amountMinor)->toBe(-350);
});

test('the wallet is read inside the snapshot, so its opening balance and legs agree', function () {
    expect(Wallet::query()->count())->toBe(1);

    $report = app(ReportQueryService::class)->walletSummary(
        $this->owner->id,
        $this->wallet->id,
        new ReportRequest(range: ReportRange::explicit('2026-10-01', '2026-10-31')),
    );

    expect($report->figures->balanceStart->amountMinor)->toBe(1000)
        ->and($report->figures->balanceEnd->amountMinor)->toBe(900)
        ->and($report->figures->expenses->amountMinor)->toBe(100);
});
