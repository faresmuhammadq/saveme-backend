<?php

use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;

/*
 * A deleted wallet's rules and occurrences go with it, in the same unit of
 * work (spec 014, R-13, FR-028; spec 010 FR-010).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
});

function deleteTheWallet(string $userId, string $walletId): void
{
    app(WalletService::class)->delete($userId, $walletId, (int) Wallet::query()->find($walletId)->version, presentWallet());
}

it('tombstones the wallet\'s rules and all their occurrences, stamped, resolved ones too, and leaves transactions to their own rule', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    $first = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, confirmBody($first));
    $beforeRule = RecurringRule::query()->findOrFail($rule->id);
    $beforeSeq = occurrencesOf($rule->id)->pluck('change_seq')->max();

    deleteTheWallet($this->owner->id, $this->wallet->id);

    $afterRule = RecurringRule::query()->findOrFail($rule->id);
    $rows = occurrencesOf($rule->id, true);

    expect($afterRule->deleted_at)->not->toBeNull()
        ->and($afterRule->version)->toBe($beforeRule->version + 1)
        ->and($afterRule->change_seq)->toBeGreaterThan($beforeSeq)
        ->and($rows->whereNull('deleted_at'))->toHaveCount(0)
        ->and($rows->pluck('change_seq')->min())->toBeGreaterThan($afterRule->change_seq)
        ->and(Transaction::query()->sole()->deleted_at)->not->toBeNull();
});

it('leaves a rule whose transfer destination was deleted live, its occurrences refusing confirmation', function () {
    $destination = ownedWallet($this->owner->id);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();

    deleteTheWallet($this->owner->id, $destination->id);

    expect(RecurringRule::query()->findOrFail($rule->id)->deleted_at)->toBeNull()
        ->and(Occurrence::query()->findOrFail($occurrence->id)->deleted_at)->toBeNull();

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence)));

    expect(fieldErrorsOf($refusal))->toBe(['/status INVALID_ENUM_VALUE']);

    $paused = saveRecurringRule($this->owner->id, $this->wallet->id, $rule->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id, 'status' => 'paused'], 1));

    expect($paused->rule->status->value)->toBe('paused');
});
