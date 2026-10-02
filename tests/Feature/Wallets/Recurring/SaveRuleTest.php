<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;

/*
 * Change the series (spec 014, US3 scenarios 1 to 6; FR-005 to FR-007;
 * invariants: resolved occurrences never change through rule edits, the
 * schedule is frozen once one is resolved, stale writes are refused whole).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
    $this->weekly = fn (array $changes = []) => recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06', ...$changes]);
});

/**
 * Saves the rule with the body at the stored version.
 *
 * @param  array<string, mixed>  $body
 */
function editRule(string $userId, string $walletId, string $ruleId, array $body): RuleView
{
    return saveRecurringRule($userId, $walletId, $ruleId, [...$body, 'version' => RecurringRule::query()->findOrFail($ruleId)->version]);
}

it('applies a template change to every scheduled, unedited occurrence and leaves the rest alone', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;
    [$confirmed, $skipped, $edited, $plain1, $plain2] = occurrencesOf($rule->id)->all();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $confirmed->id, confirmBody($confirmed));
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $skipped->id, occurrenceBody($skipped, ['status' => 'skipped']));
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $edited->id, occurrenceBody($edited, ['amount_minor' => 111]));
    $own = ownedCategory($this->owner->id, ['kind' => 'expense']);
    $beforeSeq = $plain1->fresh()->change_seq;

    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['amount_minor' => 500000, 'description' => 'Rent 2027', 'memo' => 'new', 'category_id' => $own->id]));

    $rows = occurrencesOf($rule->id)->keyBy('id');

    expect($rows[$plain1->id]->amount_minor)->toBe(500000)
        ->and($rows[$plain1->id]->description)->toBe('Rent 2027')
        ->and($rows[$plain1->id]->memo)->toBe('new')
        ->and($rows[$plain1->id]->category_id)->toBe($own->id)
        ->and($rows[$plain1->id]->version)->toBe(2)
        ->and($rows[$plain1->id]->change_seq)->toBeGreaterThan($beforeSeq)
        ->and($rows[$plain2->id]->amount_minor)->toBe(500000)
        ->and($rows[$confirmed->id]->amount_minor)->toBe(450000)
        ->and($rows[$skipped->id]->amount_minor)->toBe(450000)
        ->and($rows[$edited->id]->amount_minor)->toBe(111)
        ->and($rows[$edited->id]->description)->toBe('Rent');
    expect(CategoryReference::query()->where('wallet_id', $this->wallet->id)->where('category_id', $own->id)->whereNull('deleted_at')->count())->toBe(1);
});

it('moves a member\'s later save of a propagated occurrence into a version conflict', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    $stale = occurrenceBody($occurrence, ['status' => 'skipped']);

    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['amount_minor' => 500000]));

    $refusal = refusalOf(fn () => saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, $stale));

    expect($refusal?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($refusal->extensions['current']['amount_minor'])->toBe(500000);
});

it('recomputes the pending occurrences when the schedule changes before any is resolved, and revives a date that comes back', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['start_date' => '2026-11-01']))->rule;
    $first = occurrencesOf($rule->id)->first();

    editRule($this->owner->id, $this->wallet->id, $rule->id, recurringRuleBody(['start_date' => '2026-11-01', 'anchor_day' => 2]));

    expect(occurrencesOf($rule->id)->pluck('scheduled_date')->all())->toBe(['2026-11-02'])
        ->and($first->fresh()->deleted_at)->not->toBeNull();

    editRule($this->owner->id, $this->wallet->id, $rule->id, recurringRuleBody(['start_date' => '2026-11-01', 'anchor_day' => 1]));

    $dates = occurrencesOf($rule->id)->pluck('scheduled_date')->all();

    expect($dates)->toBe(['2026-11-01'])
        ->and($first->fresh()->deleted_at)->toBeNull()
        ->and(occurrencesOf($rule->id, true))->toHaveCount(2);
});

it('freezes the schedule once an occurrence is resolved', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;
    $first = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, occurrenceBody($first, ['status' => 'skipped']));

    $refusal = refusalOf(fn () => editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['frequency' => 'daily', 'interval' => 2, 'start_date' => '2026-10-07'])));

    expect(fieldErrorsOf($refusal))->toContain('/frequency IMMUTABLE_FIELD', '/interval IMMUTABLE_FIELD', '/start_date IMMUTABLE_FIELD');
});

it('tombstones pending occurrences past a shortened end and refuses an end that cuts off a resolved one', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;
    $rows = occurrencesOf($rule->id);
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $rows[1]->id, occurrenceBody($rows[1], ['status' => 'skipped']));

    $cut = refusalOf(fn () => editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['end_date' => '2026-10-07'])));

    expect(fieldErrorsOf($cut))->toBe(['/end_date OUT_OF_RANGE']);

    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['end_date' => '2026-10-20']));

    expect(occurrencesOf($rule->id)->pluck('scheduled_date')->all())->toBe(['2026-10-06', '2026-10-13', '2026-10-20'])
        ->and(occurrencesOf($rule->id, true)->whereNotNull('deleted_at'))->toHaveCount(2);

    $count = refusalOf(fn () => editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['occurrence_count' => 1])));

    expect(fieldErrorsOf($count))->toContain('/occurrence_count OUT_OF_RANGE');
});

it('pauses: nothing is materialised or auto-confirmed while paused, existing occurrences can still be confirmed or skipped', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)(['auto_confirm' => true]))->rule;
    $first = occurrencesOf($rule->id)->first();

    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['auto_confirm' => true, 'status' => 'paused']));
    freezeClock('2026-12-01 08:00:00');
    $totals = runRecurringJob();

    expect($totals['rules'])->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(occurrencesOf($rule->id))->toHaveCount(5);

    $view = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, confirmBody($first))->occurrence;

    expect($view->status->value)->toBe('confirmed');
});

it('resumes: materialises again and auto-confirms only from the resume day', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)(['auto_confirm' => true, 'status' => 'paused']))->rule;

    freezeClock('2026-10-20 08:00:00');
    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['auto_confirm' => true, 'status' => 'active']));

    $resumed = RecurringRule::query()->findOrFail($rule->id);

    expect($resumed->auto_confirm_from)->toBe('2026-10-20')
        ->and(occurrencesOf($rule->id)->pluck('scheduled_date')->all())->toContain('2026-10-20', '2026-10-27');

    runRecurringJob();

    expect(Transaction::query()->pluck('date')->all())->toBe(['2026-10-20']);
});

it('resets the auto-confirm start when automatic confirmation is switched on', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;

    freezeClock('2026-10-20 08:00:00');
    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['auto_confirm' => true]));

    expect(RecurringRule::query()->findOrFail($rule->id)->auto_confirm_from)->toBe('2026-10-20');

    runRecurringJob();

    expect(Transaction::query()->pluck('date')->all())->toBe(['2026-10-20']);
});

it('ends: pending occurrences after today are tombstoned, earlier ones stay, and the rule is frozen', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;
    freezeClock('2026-10-14 08:00:00');
    app(RecurringRuleService::class)->materialise($this->owner->id, $this->wallet->id, $rule->id, presentRule());

    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['status' => 'ended']));

    expect(occurrencesOf($rule->id)->pluck('scheduled_date')->all())->toBe(['2026-10-06', '2026-10-13']);

    $refusal = refusalOf(fn () => editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['status' => 'ended', 'amount_minor' => 9])));

    expect(fieldErrorsOf($refusal))->toBe(['/amount_minor IMMUTABLE_FIELD']);

    $reopen = refusalOf(fn () => editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['status' => 'active'])));

    expect(fieldErrorsOf($reopen))->toBe(['/status IMMUTABLE_FIELD']);

    $first = occurrencesOf($rule->id)->first();
    $view = saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, confirmBody($first))->occurrence;

    expect($view->status->value)->toBe('confirmed');
});

it('refuses a stale version with the stored rule, and changes nothing for an identical write', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;
    $edited = editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['amount_minor' => 500000]));

    $stale = refusalOf(fn () => saveRecurringRule($this->owner->id, $this->wallet->id, $rule->id, [...($this->weekly)(['amount_minor' => 600000]), 'version' => 1]));
    $identical = saveRecurringRule($this->owner->id, $this->wallet->id, $rule->id, [...($this->weekly)(['amount_minor' => 500000]), 'version' => 2]);

    expect($stale?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($stale->extensions['current']['amount_minor'])->toBe(500000)
        ->and($identical->rule->version)->toBe(2)
        ->and($identical->rule->changeSeq)->toBe($edited->rule->changeSeq);
});

it('refuses a change to the immutable members', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)())->rule;

    $refusal = refusalOf(fn () => editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)(['preset' => 'installment', 'kind' => 'income', 'category_id' => platformCategoryId('income'), 'currency' => 'USD', 'occurrence_count' => 6])));

    expect(fieldErrorsOf($refusal))->toContain('/preset IMMUTABLE_FIELD', '/kind IMMUTABLE_FIELD', '/currency IMMUTABLE_FIELD');
});

it('withdraws the old category\'s reference once nothing in the wallet uses it', function () {
    $own = ownedCategory($this->owner->id, ['kind' => 'expense']);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, ($this->weekly)(['category_id' => $own->id]))->rule;

    expect(CategoryReference::query()->where('category_id', $own->id)->whereNull('deleted_at')->count())->toBe(1);

    editRule($this->owner->id, $this->wallet->id, $rule->id, ($this->weekly)());

    // Occurrences moved to the platform category, but the rule's own occurrences are the only users: tombstones are not.
    expect(CategoryReference::query()->where('category_id', $own->id)->whereNull('deleted_at')->count())->toBe(0);
});
