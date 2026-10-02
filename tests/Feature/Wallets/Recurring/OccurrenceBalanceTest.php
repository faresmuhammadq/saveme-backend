<?php

use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;

/*
 * An occurrence has no postings and never counts in a balance (spec 014,
 * US1 scenario 2, FR-013, P-05).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->wallet = $this->world['wallet'];
    $this->wallet->forceFill(['opening_balance_minor' => 1_000_000, 'balance_minor' => 1_000_000])->save();
});

it('leaves the balance and the currency lock alone while occurrences are only scheduled', function () {
    createRecurringRule($this->world['owner']->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06', 'auto_confirm' => true]));
    runRecurringJob();

    expect(Posting::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(walletBalance($this->wallet->id))->toBe(1_000_000)
        ->and(app(PostingTotals::class)->hasAny($this->wallet->id))->toBeFalse();
});

it('moves the balance only when an occurrence is confirmed', function () {
    $rule = createRecurringRule($this->world['owner']->id, $this->wallet->id, recurringRuleBody(['start_date' => '2026-10-06', 'frequency' => 'daily', 'anchor_day' => null]));
    $occurrence = occurrencesOf($rule->rule->id)->first();

    saveRecurringOccurrence($this->world['owner']->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence));

    expect(walletBalance($this->wallet->id))->toBe(1_000_000 - 450_000)
        ->and(Posting::query()->count())->toBe(2);
    expectLedgerIntact();
});
