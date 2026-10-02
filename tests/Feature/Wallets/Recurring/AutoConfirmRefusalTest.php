<?php

use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * A refused automatic confirmation is surfaced, never retried (spec 014, US2
 * scenario 8, FR-018, research R-11).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->editor = $this->world['editor'];
    $this->wallet = $this->world['wallet'];
});

/**
 * An automatic rule by the editor whose first occurrence is due on 2026-11-01.
 */
function automaticRuleBy(string $authorId, string $walletId, array $changes = []): object
{
    $rule = createRecurringRule($authorId, $walletId, recurringRuleBody(['auto_confirm' => true, ...$changes]))->rule;

    return (object) ['rule' => $rule, 'occurrence' => occurrencesOf($rule->id)->first()];
}

it('refuses as not_permitted when the author was demoted to viewer, does not retry, and lets a member confirm', function () {
    $made = automaticRuleBy($this->editor->id, $this->wallet->id);
    WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->editor->id)->update(['role' => 'viewer']);

    freezeClock('2026-11-01 08:00:00');
    $first = runRecurringJob();
    $afterFirst = $made->occurrence->fresh();
    $second = runRecurringJob();

    expect($first['refused'])->toBe(1)
        ->and($afterFirst->status)->toBe('scheduled')
        ->and($afterFirst->auto_confirm_refusal)->toBe('not_permitted')
        ->and($second['refused'])->toBe(0)
        ->and($made->occurrence->fresh()->version)->toBe($afterFirst->version)
        ->and(Transaction::query()->count())->toBe(0);

    $view = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $made->occurrence->id, confirmBody($afterFirst))->occurrence;

    expect($view->status->value)->toBe('confirmed')
        ->and(Transaction::query()->sole()->created_by)->toBe($this->editor->id);
});

it('refuses as not_permitted when the author left the wallet', function () {
    $made = automaticRuleBy($this->editor->id, $this->wallet->id);
    WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->editor->id)->update(['deleted_at' => now()]);

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    expect($made->occurrence->fresh()->auto_confirm_refusal)->toBe('not_permitted');
});

it('refuses as counter_wallet_unavailable when a transfer\'s destination was deleted', function () {
    $destination = ownedWallet($this->owner->id);
    $made = automaticRuleBy($this->owner->id, $this->wallet->id, ['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]);
    Wallet::query()->whereKey($destination->id)->update(['deleted_at' => now()]);

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    expect($made->occurrence->fresh()->auto_confirm_refusal)->toBe('counter_wallet_unavailable')
        ->and($made->occurrence->fresh()->status)->toBe('scheduled');
});

it('refuses as counter_wallet_unavailable when the author left the destination', function () {
    $destination = ownedWallet($this->owner->id);
    $made = automaticRuleBy($this->owner->id, $this->wallet->id, ['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]);
    WalletMember::query()->where('wallet_id', $destination->id)->where('user_id', $this->owner->id)->update(['deleted_at' => now()]);

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    expect($made->occurrence->fresh()->auto_confirm_refusal)->toBe('counter_wallet_unavailable');
});

it('refuses as currency_mismatch when the destination\'s currency changed', function () {
    $destination = ownedWallet($this->owner->id);
    $made = automaticRuleBy($this->owner->id, $this->wallet->id, ['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id]);
    Wallet::query()->whereKey($destination->id)->update(['currency' => 'USD']);

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    expect($made->occurrence->fresh()->auto_confirm_refusal)->toBe('currency_mismatch');
});

it('lets a member skip a refused occurrence, and confirms the others of the rule', function () {
    $made = automaticRuleBy($this->editor->id, $this->wallet->id, ['frequency' => 'daily', 'anchor_day' => null, 'start_date' => '2026-10-05']);
    $rows = occurrencesOf($made->rule->id);
    Occurrence::query()->whereKey($rows->first()->id)->update(['auto_confirm_refusal' => 'not_permitted']);

    freezeClock('2026-10-06 08:00:00');
    runRecurringJob();

    expect(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->sole()->date)->toBe('2026-10-06');

    $skipped = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $rows->first()->id, occurrenceBody($rows->first()->id, ['status' => 'skipped']))->occurrence;

    expect($skipped->status->value)->toBe('skipped')->and($skipped->autoConfirmRefusal)->toBeNull();
});

it('confirms on the next pass an occurrence whose refusal a changing save cleared', function () {
    $made = automaticRuleBy($this->editor->id, $this->wallet->id);
    $member = WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->editor->id)->first();
    $member->forceFill(['role' => 'viewer'])->save();

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    $member->forceFill(['role' => 'editor'])->save();
    runRecurringJob();

    expect(Transaction::query()->count())->toBe(0);

    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $made->occurrence->id, occurrenceBody($made->occurrence, ['memo' => 'fixed the cause']));
    runRecurringJob();

    expect(Transaction::query()->count())->toBe(1)
        ->and($made->occurrence->fresh()->status)->toBe('confirmed');
});
