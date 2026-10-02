<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Infrastructure\Models\Transaction;

/*
 * Delete a rule: a tombstone cascading to its occurrences, never to the
 * transactions it produced (spec 014, US3 scenario 7, FR-008).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
    $this->rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
});

it('tombstones the rule and every occurrence, resolved too, with stamps, leaving the transactions alone', function () {
    [$first, $second] = occurrencesOf($this->rule->id)->all();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, confirmBody($first));
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $second->id, occurrenceBody($second, ['status' => 'skipped']));
    $before = occurrencesOf($this->rule->id);

    $view = app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $this->rule->id, 1, presentRule());
    $rows = occurrencesOf($this->rule->id, true);

    expect($view->rule->isTombstone())->toBeTrue()
        ->and($view->rule->version)->toBe(2)
        ->and($rows->whereNull('deleted_at'))->toHaveCount(0)
        ->and($rows->pluck('version')->unique()->all())->each->toBeGreaterThanOrEqual(2)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->sole()->deleted_at)->toBeNull();

    $stamps = [$view->rule->changeSeq, ...$rows->pluck('change_seq')->all()];

    expect(array_unique($stamps))->toHaveCount(count($stamps))
        ->and((int) $this->wallet->fresh()->change_seq)->toBeGreaterThan(max($stamps))
        ->and($rows->pluck('change_seq')->min())->toBeGreaterThan($before->pluck('change_seq')->max());
});

it('returns the tombstone unchanged when deleting a tombstone, whatever version is sent', function () {
    $service = app(RecurringRuleService::class);
    $first = $service->delete($this->owner->id, $this->wallet->id, $this->rule->id, 1, presentRule());
    $again = $service->delete($this->owner->id, $this->wallet->id, $this->rule->id, 99, presentRule());

    expect($again->rule->version)->toBe($first->rule->version)->and($again->rule->changeSeq)->toBe($first->rule->changeSeq);
});

it('refuses a stale version on a live rule', function () {
    $refusal = refusalOf(fn () => app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $this->rule->id, 5, presentRule()));

    expect($refusal?->errorCode)->toBe(ErrorCode::VersionConflict)->and($refusal->extensions['current']['version'])->toBe(1);
});

it('refuses an edit or a catch-up of a tombstone as a conflict carrying it', function () {
    app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $this->rule->id, 1, presentRule());

    $edit = refusalOf(fn () => saveRecurringRule($this->owner->id, $this->wallet->id, $this->rule->id, recurringRuleBody(['amount_minor' => 9], 2)));
    $catchUp = refusalOf(fn () => app(RecurringRuleService::class)->materialise($this->owner->id, $this->wallet->id, $this->rule->id, presentRule()));

    expect($edit?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($edit->extensions['current']['deleted_at'])->not->toBeNull()
        ->and($catchUp?->errorCode)->toBe(ErrorCode::VersionConflict);
});

it('is final: the job never touches it again', function () {
    app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $this->rule->id, 1, presentRule());
    freezeClock('2026-12-01 08:00:00');

    $totals = runRecurringJob();

    expect($totals['rules'])->toBe(0)->and(occurrencesOf($this->rule->id))->toHaveCount(0);
});
