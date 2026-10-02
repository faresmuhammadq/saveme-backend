<?php

use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Domain\Recurring\OccurrencePlan;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Transaction;

/*
 * One occurrence per occurrence key and one transaction per occurrence,
 * whatever reruns or overlaps happen (spec 014, FR-009, FR-019).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
});

it('materialises nothing new on a rerun', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-05']))->rule;
    $before = occurrencesOf($rule->id, true)->pluck('id', 'scheduled_date')->all();

    runRecurringJob();
    runRecurringJob();
    app(RecurringRuleService::class)->materialise($this->owner->id, $this->wallet->id, $rule->id, presentRule());

    expect(occurrencesOf($rule->id, true)->pluck('id', 'scheduled_date')->all())->toBe($before);
});

it('creates each occurrence key once as the clock moves and the job and materialise interleave', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-05']))->rule;

    foreach (['2026-10-12 08:00:00', '2026-10-19 08:00:00', '2026-10-26 08:00:00'] as $instant) {
        freezeClock($instant);
        runRecurringJob();
        app(RecurringRuleService::class)->materialise($this->owner->id, $this->wallet->id, $rule->id, presentRule());
        runRecurringJob();
    }

    $keys = Occurrence::query()->where('recurring_rule_id', $rule->id)->get()->map(fn (Occurrence $occurrence): string => $occurrence->scheduled_date.($occurrence->is_payout ? 'p' : ''));

    expect($keys->unique())->toHaveCount($keys->count())
        ->and(Transaction::query()->whereNotNull('occurrence_id')->distinct()->count('occurrence_id'))->toBe(Transaction::query()->count())
        ->and(Transaction::query()->count())->toBe(Occurrence::query()->where('status', 'confirmed')->count());
});

it('does not duplicate an occurrence even if the unique insert is raced', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-05']))->rule;
    $count = Occurrence::query()->count();

    $plan = new OccurrencePlan(1, false, '2026-10-05', TransactionKind::Expense, 1, 'EGP', $rule->categoryId, null, null, null, null);
    $inserted = app(OccurrenceRepository::class)->insertMany($rule->id, $this->wallet->id, [$plan], 999);

    expect($inserted)->toBe(0)
        ->and(Occurrence::query()->count())->toBe($count);
});
