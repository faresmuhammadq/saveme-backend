<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * Edit one, skip, un-skip (spec 014, US2 scenarios 1, 5 to 7; FR-014,
 * FR-015). Confirmation is ConfirmOccurrenceTest's.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
    $this->rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    $this->occurrence = occurrencesOf($this->rule->id)->first();
});

it('edits the date, amount, category and texts: edited, version + 1, a new change_seq', function () {
    $own = ownedCategory($this->owner->id, ['kind' => 'expense']);
    $before = $this->occurrence->change_seq;

    $view = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, [
        'date' => '2027-03-01', 'amount_minor' => 61200, 'category_id' => $own->id, 'description' => '  Electricity ', 'memo' => 'late', 'counterparty' => 'Utility',
    ]))->occurrence;

    expect($view->edited)->toBeTrue()
        ->and($view->version)->toBe(2)
        ->and($view->changeSeq)->toBeGreaterThan($before)
        ->and($view->date)->toBe('2027-03-01')
        ->and($view->scheduledDate)->toBe('2026-10-06')
        ->and($view->amountMinor)->toBe(61200)
        ->and($view->categoryId)->toBe($own->id)
        ->and($view->description)->toBe('Electricity')
        ->and($view->status->value)->toBe('scheduled');
});

it('clears edited when the rule-derived values are sent back', function () {
    $edited = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['amount_minor' => 1]))->occurrence;
    $reverted = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['amount_minor' => 450000]))->occurrence;

    expect($edited->edited)->toBeTrue()->and($reverted->edited)->toBeFalse()->and($reverted->version)->toBe(3);
});

it('skips and un-skips', function () {
    $skipped = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['status' => 'skipped']))->occurrence;
    $back = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['status' => 'scheduled']))->occurrence;

    expect($skipped->status->value)->toBe('skipped')->and($skipped->transactionId)->toBeNull()
        ->and($back->status->value)->toBe('scheduled')->and($back->version)->toBe(3)
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses any change to a confirmed occurrence at the member', function () {
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, confirmBody($this->occurrence));

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['amount_minor' => 5, 'status' => 'skipped', 'transaction_id' => null])));

    expect($refusal?->errorCode)->toBe(ErrorCode::ValidationFailed)
        ->and(fieldErrorsOf($refusal))->toBe(['/status IMMUTABLE_FIELD', '/amount_minor IMMUTABLE_FIELD', '/transaction_id IMMUTABLE_FIELD']);
});

it('answers not-found for an id the server never materialised and for another wallet\'s occurrence', function () {
    $other = ownedWallet($this->owner->id);

    expect(refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, (string) Str::uuid7(), occurrenceBody($this->occurrence)))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $other->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['status' => 'skipped'])))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(Occurrence::query()->count())->toBe(occurrencesOf($this->rule->id)->count());
});

it('refuses a stale version with the stored occurrence in current and merges nothing', function () {
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['amount_minor' => 70000]));

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, ['status' => 'skipped', 'date' => '2026-10-06', 'amount_minor' => 450000, 'category_id' => $this->occurrence->category_id, 'description' => 'Rent', 'memo' => null, 'counterparty' => null, 'transaction_id' => null, 'version' => 1]));

    expect($refusal?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($refusal->extensions['current']['amount_minor'])->toBe(70000)
        ->and($refusal->extensions['current']['version'])->toBe(2)
        ->and($this->occurrence->fresh()->status)->toBe('scheduled');
});

it('refuses a write to a tombstone even when identical', function () {
    Occurrence::query()->whereKey($this->occurrence->id)->update(['deleted_at' => now(), 'version' => 2]);

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence)));

    expect($refusal?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($refusal->extensions['current']['deleted_at'])->not->toBeNull();
});

it('changes nothing for an identical replay', function () {
    $first = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['status' => 'skipped']))->occurrence;
    $replay = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, ['status' => 'skipped', 'date' => '2026-10-06', 'amount_minor' => 450000, 'category_id' => $this->occurrence->category_id, 'description' => 'Rent', 'memo' => null, 'counterparty' => null, 'transaction_id' => null, 'version' => 1])->occurrence;

    expect($replay->version)->toBe($first->version)->and($replay->changeSeq)->toBe($first->changeSeq);
});

it('clears an automatic confirmation refusal on a changing save, not on an identical one', function () {
    Occurrence::query()->whereKey($this->occurrence->id)->update(['auto_confirm_refusal' => 'not_permitted']);
    $same = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence))->occurrence;

    expect($same->autoConfirmRefusal?->value)->toBe('not_permitted');

    $changed = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['memo' => 'fixed']))->occurrence;

    expect($changed->autoConfirmRefusal)->toBeNull();
});

it('checks an edited category: the kind, own or platform unless unchanged, never on a transfer', function () {
    $income = ownedCategory($this->owner->id, ['kind' => 'income']);
    $foreign = ownedCategory($this->world['editor']->id, ['kind' => 'expense']);

    $wrongKind = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['category_id' => $income->id])));
    $notOwn = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['category_id' => $foreign->id])));
    $platform = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $this->occurrence->id, occurrenceBody($this->occurrence, ['category_id' => platformCategoryId('expense')]))->occurrence;

    expect(fieldErrorsOf($wrongKind))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(fieldErrorsOf($notOwn))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and($platform->categoryId)->toBe(platformCategoryId('expense'));

    $destination = ownedWallet($this->owner->id);
    $transfer = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    $moved = occurrencesOf($transfer->id)->first();
    $withCategory = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $moved->id, occurrenceBody($moved, ['category_id' => platformCategoryId()])));

    expect(fieldErrorsOf($withCategory))->toBe(['/category_id INVALID_ENUM_VALUE']);
});

it('keeps an unchanged category whoever\'s it is and refuses a deleted one', function () {
    $partners = ownedCategory($this->world['editor']->id, ['kind' => 'expense']);
    $rule = createRecurringRule($this->world['editor']->id, $this->wallet->id, recurringRuleBody(['category_id' => $partners->id, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    $theirs = occurrencesOf($rule->id)->first();

    $view = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $theirs->id, occurrenceBody($theirs, ['memo' => 'owner edits']))->occurrence;

    expect($view->categoryId)->toBe($partners->id);
});
