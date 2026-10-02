<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Infrastructure\Models\Transaction;

/*
 * Catch-up on request (spec 014, US5 scenario 1, FR-021): does what the job
 * does for one rule, idempotent.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
    $this->service = app(RecurringRuleService::class);
});

it('extends the horizon and confirms what a missed job run left due', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;

    freezeClock('2026-10-21 08:00:00');
    $view = $this->service->materialise($this->owner->id, $this->wallet->id, $rule->id, presentRule());

    expect(Transaction::query()->orderBy('date')->pluck('date')->all())->toBe(['2026-10-06', '2026-10-13', '2026-10-20'])
        ->and(occurrencesOf($rule->id)->pluck('scheduled_date')->last())->toBe('2026-11-24')
        ->and($view->rule->version)->toBe(1)
        ->and($view->rule->changeSeq)->toBe($rule->changeSeq);
});

it('changes nothing the second time', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    freezeClock('2026-10-21 08:00:00');
    $this->service->materialise($this->owner->id, $this->wallet->id, $rule->id, presentRule());
    $stamps = occurrencesOf($rule->id)->pluck('change_seq', 'id')->all();
    $wallet = (int) $this->wallet->fresh()->change_seq;

    $this->service->materialise($this->owner->id, $this->wallet->id, $rule->id, presentRule());

    expect(occurrencesOf($rule->id)->pluck('change_seq', 'id')->all())->toBe($stamps)
        ->and((int) $this->wallet->fresh()->change_seq)->toBe($wallet)
        ->and(Transaction::query()->count())->toBe(3);
});

it('returns a paused or an ended rule unchanged', function () {
    $paused = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['status' => 'paused', 'auto_confirm' => true]))->rule;
    $ended = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['description' => 'Gym']))->rule;
    saveRecurringRule($this->owner->id, $this->wallet->id, $ended->id, recurringRuleBody(['description' => 'Gym', 'status' => 'ended'], 1));

    freezeClock('2026-12-01 08:00:00');
    $pausedView = $this->service->materialise($this->owner->id, $this->wallet->id, $paused->id, presentRule());
    $endedView = $this->service->materialise($this->owner->id, $this->wallet->id, $ended->id, presentRule());

    expect($pausedView->rule->status->value)->toBe('paused')
        ->and($endedView->rule->status->value)->toBe('ended')
        ->and(occurrencesOf($paused->id))->toHaveCount(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses a tombstone as a conflict carrying it, a viewer as forbidden and a non-member as not-found', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id)->rule;
    $stranger = person('stranger@example.test');

    expect(refusalOf(fn () => $this->service->materialise($this->world['viewer']->id, $this->wallet->id, $rule->id, presentRule()))?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(refusalOf(fn () => $this->service->materialise($stranger->id, $this->wallet->id, $rule->id, presentRule()))?->errorCode)->toBe(ErrorCode::NotFound);

    $this->service->delete($this->owner->id, $this->wallet->id, $rule->id, 1, presentRule());
    $conflict = refusalOf(fn () => $this->service->materialise($this->owner->id, $this->wallet->id, $rule->id, presentRule()));

    expect($conflict?->errorCode)->toBe(ErrorCode::VersionConflict)->and($conflict->extensions['current']['deleted_at'])->not->toBeNull();
});
