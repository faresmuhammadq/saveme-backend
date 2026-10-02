<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Str;

/*
 * Confirming one occurrence records one transaction with the client's id in
 * the same unit of work (spec 014, US2 scenarios 2 and 3; FR-016; invariant:
 * one transaction per occurrence).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->editor = $this->world['editor'];
    $this->wallet = $this->world['wallet'];
    $this->rule = createRecurringRule($this->owner->id, $this->wallet->id)->rule;
    $this->occurrence = occurrencesOf($this->rule->id)->first();
});

it('records one transaction with the id and the values of the same write, linked, as the author, cleared false', function () {
    $id = (string) Str::uuid7();

    $view = saveRecurringOccurrence($this->editor->id, $this->wallet->id, $this->occurrence->id, confirmBody($this->occurrence, [
        'transaction_id' => $id, 'amount_minor' => 61200, 'date' => '2026-11-03', 'description' => 'Electricity', 'memo' => 'paid cash',
    ]))->occurrence;
    $transaction = Transaction::query()->sole();

    expect($view->status->value)->toBe('confirmed')
        ->and($view->transactionId)->toBe($id)
        ->and($view->edited)->toBeTrue()
        ->and($transaction->id)->toBe($id)
        ->and($transaction->amount_minor)->toBe(61200)
        ->and($transaction->date)->toBe('2026-11-03')
        ->and($transaction->description)->toBe('Electricity')
        ->and($transaction->memo)->toBe('paid cash')
        ->and($transaction->created_by)->toBe($this->owner->id)
        ->and($transaction->cleared)->toBeFalse()
        ->and($transaction->occurrence_id)->toBe($this->occurrence->id)
        ->and($transaction->recurring_rule_id)->toBe($this->rule->id)
        ->and(walletBalance($this->wallet->id))->toBe(-61200);
    expectLedgerIntact();
    expectTransactionScopeIntact($this->wallet->id);
});

it('confirms a skipped occurrence and one dated in the future', function () {
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['status' => 'skipped']));

    $view = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, confirmBody($this->occurrence))->occurrence;

    expect($view->status->value)->toBe('confirmed')
        ->and(Transaction::query()->sole()->date)->toBe('2026-11-01');
});

it('changes nothing for an identical replay: no second transaction', function () {
    $body = confirmBody($this->occurrence);
    $first = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, $body)->occurrence;
    $replay = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, $body)->occurrence;

    expect($replay->version)->toBe($first->version)
        ->and($replay->changeSeq)->toBe($first->changeSeq)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(walletBalance($this->wallet->id))->toBe(-450000);
});

it('refuses a transaction id any record already holds, applying nothing', function (string $holder) {
    $taken = match ($holder) {
        'a transaction' => recordTransaction($this->owner->id, $this->wallet->id)->transaction->id,
        'a wallet' => $this->wallet->id,
        'a category' => ownedCategory($this->owner->id)->id,
        'a rule' => $this->rule->id,
    };
    $transactions = Transaction::query()->count();

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, confirmBody($this->occurrence, ['transaction_id' => $taken])));

    expect(fieldErrorsOf($refusal))->toBe(['/transaction_id INVALID_ENUM_VALUE'])
        ->and($this->occurrence->fresh()->status)->toBe('scheduled')
        ->and(Transaction::query()->count())->toBe($transactions);
})->with(['a transaction', 'a wallet', 'a category', 'a rule']);

it('refuses the same transaction id for a second occurrence', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    [$first, $second] = occurrencesOf($rule->id)->all();
    $id = (string) Str::uuid7();

    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, confirmBody($first, ['transaction_id' => $id]));
    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $second->id, confirmBody($second, ['transaction_id' => $id])));

    expect(fieldErrorsOf($refusal))->toBe(['/transaction_id INVALID_ENUM_VALUE'])->and(Transaction::query()->count())->toBe(1);
});

it('refuses a confirmation whose destination was deleted at /status, applying nothing', function () {
    $destination = ownedWallet($this->owner->id);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    Wallet::query()->whereKey($destination->id)->update(['deleted_at' => now()]);

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence)));

    expect(fieldErrorsOf($refusal))->toBe(['/status INVALID_ENUM_VALUE'])
        ->and($occurrence->fresh()->status)->toBe('scheduled')
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses a confirmation whose destination left the caller at /status', function () {
    $destination = ownedWallet($this->owner->id);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    WalletMember::query()->where('wallet_id', $destination->id)->where('user_id', $this->owner->id)->update(['deleted_at' => now()]);

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence)));

    expect(fieldErrorsOf($refusal))->toBe(['/status INVALID_ENUM_VALUE']);
});

it('refuses a confirmation when the destination\'s currency changed, at /status', function () {
    $destination = ownedWallet($this->owner->id);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    Wallet::query()->whereKey($destination->id)->update(['currency' => 'USD']);

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence)));

    expect(fieldErrorsOf($refusal))->toBe(['/status CURRENCY_MISMATCH'])
        ->and(Transaction::query()->count())->toBe(0);
});

it('moves both balances for a confirmed transfer', function () {
    $destination = ownedWallet($this->owner->id);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();

    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence));

    expect(walletBalance($this->wallet->id))->toBe(-450000)
        ->and(walletBalance($destination->id))->toBe(450000);
    expectLedgerIntact();
});

it('is forbidden when the caller lacks T-02 in the destination, applying nothing', function () {
    $destination = ownedWallet($this->owner->id);
    $there = addMember($destination->id, $this->editor->id, 'editor');
    $rule = createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    $there->forceFill(['role' => 'viewer'])->save();

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->editor->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence)));

    expect($refusal?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(Occurrence::query()->whereKey($occurrence->id)->value('status'))->toBe('scheduled');
});

it('publishes the reference of a person\'s category used by an edited occurrence', function () {
    $own = ownedCategory($this->owner->id, ['kind' => 'expense']);

    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, confirmBody($this->occurrence, ['category_id' => $own->id]));

    expect(CategoryReference::query()->where('wallet_id', $this->wallet->id)->where('category_id', $own->id)->whereNull('deleted_at')->count())->toBe(1);
});
