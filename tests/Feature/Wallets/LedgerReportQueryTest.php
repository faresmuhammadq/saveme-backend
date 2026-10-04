<?php

use App\Modules\Wallets\Application\Reports\Dto\ReportWallet;
use App\Modules\Wallets\Application\Reports\LedgerReportQuery;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The ledger sums reports read (spec 019, R-2, R-3, R-6; FR-004, FR-006):
 * readable wallets, wallet-leg totals, balances, category and daily totals,
 * scheduled totals and the snapshot.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 100000]);
    $this->query = app(LedgerReportQuery::class);
});

test('readableWallet serves the id, currency and opening balance to owner, editor and viewer', function () {
    $editor = person('editor@example.test');
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');
    addMember($this->wallet->id, $viewer->id, 'viewer');

    foreach ([$this->owner, $editor, $viewer] as $user) {
        $wallet = $this->query->readableWallet($user->id, $this->wallet->id);

        expect($wallet)->toEqual(new ReportWallet($this->wallet->id, 'EGP', 100000));
    }
});

test('readableWallet refuses a non-member, a former member and a deleted wallet', function () {
    $stranger = person('stranger@example.test');
    $former = person('former@example.test');
    $member = addMember($this->wallet->id, $former->id, 'editor');
    WalletMember::query()->whereKey($member->id)->update(['deleted_at' => now()]);
    $deleted = ownedWallet($this->owner->id);
    Wallet::query()->whereKey($deleted->id)->update(['deleted_at' => now()]);

    expect(fn () => $this->query->readableWallet($stranger->id, $this->wallet->id))->toThrow(WalletNotFound::class)
        ->and(fn () => $this->query->readableWallet($former->id, $this->wallet->id))->toThrow(WalletNotFound::class)
        ->and(fn () => $this->query->readableWallet($this->owner->id, $deleted->id))->toThrow(WalletNotFound::class)
        ->and(fn () => $this->query->readableWallet($this->owner->id, (string) Str::uuid7()))->toThrow(WalletNotFound::class);
});

test('walletsOf lists live memberships of live wallets, archived included', function () {
    $archived = ownedWallet($this->owner->id, ['archived_at' => now(), 'currency' => 'USD', 'opening_balance_minor' => 5]);
    $gone = ownedWallet($this->owner->id);
    Wallet::query()->whereKey($gone->id)->update(['deleted_at' => now()]);
    $left = ownedWallet(person('other@example.test')->id);
    $leftMember = addMember($left->id, $this->owner->id, 'viewer');
    WalletMember::query()->whereKey($leftMember->id)->update(['deleted_at' => now()]);
    ownedWallet(person('third@example.test')->id);

    $wallets = collect($this->query->walletsOf($this->owner->id))->keyBy('id');

    expect($wallets->keys()->sort()->values()->all())->toBe(collect([$this->wallet->id, $archived->id])->sort()->values()->all())
        ->and($wallets[$archived->id]->currency)->toBe('USD')
        ->and($wallets[$archived->id]->openingBalanceMinor)->toBe(5);
});

test('wallet leg totals carry kind, sign and the other wallet of a transfer on both sides', function () {
    $bank = ownedWallet($this->owner->id, ['opening_balance_minor' => 0]);
    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 10000, 'date' => '2026-10-02']));
    spend($this->owner->id, $this->wallet->id, 3000, ['date' => '2026-10-03']);
    recordTransaction($this->owner->id, $this->wallet->id, transferBody($bank->id, ['amount_minor' => 2000, 'date' => '2026-10-04']));
    spend($this->owner->id, $this->wallet->id, 999, ['date' => '2026-09-30']);

    $rows = collect($this->query->walletLegTotals([$this->wallet->id, $bank->id], '2026-10-01', '2026-10-31'))
        ->map(fn ($row) => [$row->walletId === $this->wallet->id ? 'cash' : 'bank', $row->kind, $row->otherWalletId === null ? null : ($row->otherWalletId === $bank->id ? 'bank' : 'cash'), $row->amountMinor])
        ->sort()->values()->all();

    expect($rows)->toBe(collect([
        ['bank', 'transfer', 'cash', 2000],
        ['cash', 'expense', null, -3000],
        ['cash', 'income', null, 10000],
        ['cash', 'transfer', 'bank', -2000],
    ])->sort()->values()->all());
});

test('balancesBefore sums legs dated strictly before the date and ignores deleted transactions', function () {
    spend($this->owner->id, $this->wallet->id, 3000, ['date' => '2026-09-30']);
    spend($this->owner->id, $this->wallet->id, 500, ['date' => '2026-10-01']);
    $gone = spend($this->owner->id, $this->wallet->id, 700, ['date' => '2026-09-29']);
    app(TransactionService::class)->delete($this->owner->id, $this->wallet->id, $gone->transaction->id, 1, presentTransaction());

    expect($this->query->balancesBefore([$this->wallet->id], '2026-10-01'))->toBe([$this->wallet->id => -3000])
        ->and($this->query->balancesBefore([$this->wallet->id], '2026-09-01'))->toBe([]);
});

test('categoryTotals groups category legs with the system key, expense positive and income negative', function () {
    $own = ownedCategory($this->owner->id);
    $groceries = platformCategoryId();
    spend($this->owner->id, $this->wallet->id, 1000, ['category_id' => $groceries]);
    spend($this->owner->id, $this->wallet->id, 250, ['category_id' => $groceries]);
    spend($this->owner->id, $this->wallet->id, 400, ['category_id' => $own->id]);
    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 9000]));

    $rows = collect($this->query->categoryTotals([$this->wallet->id], '2026-10-01', '2026-10-31'))->keyBy('categoryId');
    $key = Category::query()->whereKey($groceries)->value('system_key');

    expect($rows[$groceries]->amountMinor)->toBe(1250)
        ->and($rows[$groceries]->systemKey)->toBe($key)
        ->and($rows[$own->id]->amountMinor)->toBe(400)
        ->and($rows[$own->id]->systemKey)->toBeNull()
        ->and($rows->firstWhere('kind', 'income')->amountMinor)->toBe(-9000);
});

test('dailyTotals groups income and expense legs by date', function () {
    spend($this->owner->id, $this->wallet->id, 1000, ['date' => '2026-10-02']);
    spend($this->owner->id, $this->wallet->id, 200, ['date' => '2026-10-02']);
    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 5000, 'date' => '2026-10-03']));
    recordTransaction($this->owner->id, $this->wallet->id, transferBody(ownedWallet($this->owner->id)->id, ['amount_minor' => 77, 'date' => '2026-10-03']));

    $rows = collect($this->query->dailyTotals([$this->wallet->id], '2026-10-01', '2026-10-31'))->map(fn ($row) => [$row->date, $row->kind, $row->amountMinor])->sort()->values()->all();

    expect($rows)->toBe([['2026-10-02', 'expense', -1200], ['2026-10-03', 'income', 5000]]);
});

test('scheduledTotals counts only scheduled live occurrences in the range, currency and wallets', function () {
    $bank = ownedWallet($this->owner->id);
    $usd = ownedWallet($this->owner->id, ['currency' => 'USD']);
    $rent = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['start_date' => '2026-10-20']));
    $usdRule = createRecurringRule($this->owner->id, $usd->id, recurringRuleBody(['start_date' => '2026-10-20', 'currency' => 'USD']));
    $move = createRecurringRule($this->owner->id, $bank->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $this->wallet->id, 'start_date' => '2026-10-21']));

    $first = occurrencesOf($rent->rule->id)->first();
    Occurrence::query()->whereKey($first->id)->update(['date' => '2026-10-20']);
    $skipped = occurrencesOf($move->rule->id)->first();
    Occurrence::query()->whereKey($skipped->id)->update(['date' => '2026-10-21']);
    $usdFirst = occurrencesOf($usdRule->rule->id)->first();
    Occurrence::query()->whereKey($usdFirst->id)->update(['date' => '2026-10-22']);
    $rows = fn (array $ids, string $currency = 'EGP') => collect($this->query->scheduledTotals($ids, $currency, '2026-10-01', '2026-10-31'));

    expect($rows([$this->wallet->id])->where('kind', 'expense')->sum('amountMinor'))->toBe(450000)
        ->and($rows([$this->wallet->id])->where('kind', 'transfer')->count())->toBe(1)
        ->and($rows([$this->wallet->id])->firstWhere('kind', 'transfer')->counterWalletId)->toBe($this->wallet->id)
        ->and($rows([$usd->id], 'USD')->count())->toBe(1)
        ->and($rows([$usd->id])->count())->toBe(0)
        ->and($rows([$this->wallet->id], 'USD')->where('kind', 'expense')->count())->toBe(0);

    saveRecurringOccurrence($this->owner->id, $bank->id, $skipped->id, occurrenceBody($skipped, ['status' => 'skipped']));
    Occurrence::query()->whereKey($first->id)->update(['status' => 'confirmed', 'transaction_id' => (string) Str::uuid7()]);

    expect($rows([$this->wallet->id, $bank->id])->count())->toBe(0)
        ->and($usdRule->rule->id)->not->toBeEmpty();
});

test('a corrupted balance cache changes no report sum', function () {
    spend($this->owner->id, $this->wallet->id, 3000, ['date' => '2026-10-02']);
    Wallet::query()->whereKey($this->wallet->id)->update(['balance_minor' => 999999999]);

    $rows = $this->query->walletLegTotals([$this->wallet->id], '2026-10-01', '2026-10-31');

    expect($rows)->toHaveCount(1)->and($rows[0]->amountMinor)->toBe(-3000)
        ->and($this->query->readableWallet($this->owner->id, $this->wallet->id)->openingBalanceMinor)->toBe(100000);
});

test('a snapshot runs its reads in one repeatable-read, read-only transaction', function () {
    // The transaction RefreshDatabase opened is committed: a snapshot may set
    // its isolation only as the first statement of a transaction it opens.
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }

    $inside = $this->query->snapshot(fn (): array => [
        'isolation' => DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
        'read_only' => DB::selectOne('SHOW transaction_read_only')->transaction_read_only,
    ]);

    expect($inside)->toBe(['isolation' => 'repeatable read', 'read_only' => 'on'])
        ->and(fn () => $this->query->snapshot(fn () => DB::statement("UPDATE wallets SET name = 'x'")))->toThrow(QueryException::class);

    // Committed rows of this test, removed so the next test starts clean.
    DB::table('wallet_members')->delete();
    DB::table('wallets')->delete();
    DB::table('profiles')->delete();
    DB::table('users')->delete();
});
