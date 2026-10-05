<?php

use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * The ledger outlives its author (spec 022, FR-041, FR-042, SC-005; P-04,
 * P-05): no ledger row is rewritten, no balance moves, `created_by` keeps the
 * departed id, and a departed author's recurring rule keeps running without
 * their authority.
 */

beforeEach(function () {
    Mail::fake();
    freezeClock('2026-10-05 10:00:00');
    $this->world = exportWorld();
    $this->d = $this->world['d'];
    $this->survivors = [$this->world['w']->id, $this->world['r']->id, $this->world['l']->id];
});

/**
 * Every ledger row of the surviving wallets and each wallet's derived balance.
 *
 * @return array{transactions: array<int, object>, postings: array<int, object>, balances: array<string, int>, cached: array<string, int>}
 */
function ledgerOf(array $walletIds): array
{
    $balances = DB::table('wallets')->whereIn('id', $walletIds)->orderBy('id')->get()->mapWithKeys(fn ($wallet): array => [
        $wallet->id => (int) $wallet->opening_balance_minor + (int) DB::table('postings')->where('wallet_id', $wallet->id)->sum('amount_minor'),
    ])->all();

    return [
        'transactions' => DB::table('transactions')->whereIn('wallet_id', $walletIds)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'postings' => DB::table('postings')->whereIn('wallet_id', $walletIds)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'balances' => $balances,
        'cached' => DB::table('wallets')->whereIn('id', $walletIds)->orderBy('id')->pluck('balance_minor', 'id')->map(fn ($value): int => (int) $value)->all(),
    ];
}

test('balances, transaction counts, versions and sequences of every surviving wallet are identical before and after', function () {
    $before = ledgerOf($this->survivors);
    expect($before['transactions'])->toHaveCount(5);

    purgeAccountNow($this->d->id);

    $after = ledgerOf($this->survivors);
    expect($after['transactions'])->toBe($before['transactions'])
        ->and($after['postings'])->toBe($before['postings'])
        ->and($after['balances'])->toBe($before['balances'])
        ->and($after['cached'])->toBe($before['cached']);
});

test('the departed person\'s entries keep their id as the author marker, and their wallet-mates\' are untouched', function () {
    purgeAccountNow($this->d->id);

    expect(DB::table('transactions')->where('id', $this->world['mine']->transaction->id)->value('created_by'))->toBe($this->d->id)
        ->and(DB::table('transactions')->where('id', $this->world['inL']->transaction->id)->value('created_by'))->toBe($this->d->id)
        ->and(DB::table('transactions')->where('id', $this->world['fromPat']->transaction->id)->value('created_by'))->toBe($this->world['p']->id);
});

test('the departed id resolves to no one: a husk with no attribute', function () {
    purgeAccountNow($this->d->id);

    $husk = DB::table('users')->where('id', $this->d->id)->first();
    expect($husk->state)->toBe('purged')
        ->and($husk->email_ciphertext)->toBeNull()->and($husk->email_lookup)->toBeNull()->and($husk->password_hash)->toBeNull()
        ->and(DB::table('profiles')->where('user_id', $this->d->id)->exists())->toBeFalse();
});

test('a departed author\'s automatic rule is untouched and its next occurrence is refused as not_permitted', function () {
    $made = createRecurringRule($this->world['p']->id, $this->world['r']->id, recurringRuleBody(['auto_confirm' => true, 'description' => 'Pat rent']))->rule;
    $rule = createRecurringRule($this->d->id, $this->world['w']->id, recurringRuleBody(['auto_confirm' => true]))->rule;
    $ruleBefore = (array) DB::table('recurring_rules')->where('id', $rule->id)->first();
    $occurrence = occurrencesOf($rule->id)->first();

    purgeAccountNow($this->d->id);

    expect((array) DB::table('recurring_rules')->where('id', $rule->id)->first())->toBe($ruleBefore)
        ->and(RecurringRule::query()->find($rule->id)->created_by)->toBe($this->d->id);

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    expect(Occurrence::query()->find($occurrence->id)->auto_confirm_refusal)->toBe('not_permitted')
        ->and(Occurrence::query()->find($occurrence->id)->status)->toBe('scheduled')
        ->and(DB::table('transactions')->where('occurrence_id', $occurrence->id)->count())->toBe(0);
});

test('no balance or version of a wallet the person owned moves except through the transfer itself', function () {
    $w = $this->world['w'];
    $before = DB::table('wallets')->where('id', $w->id)->first();

    purgeAccountNow($this->d->id);

    $after = DB::table('wallets')->where('id', $w->id)->first();
    expect($after->balance_minor)->toBe($before->balance_minor)
        ->and($after->opening_balance_minor)->toBe($before->opening_balance_minor)
        ->and($after->name)->toBe($before->name)
        ->and($after->deleted_at)->toBeNull();
});
