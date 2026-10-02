<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Wallets\Domain\Events\TransactionPosted;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/*
 * The hourly job confirms due occurrences of automatic rules, once, as the
 * rule's author, in the rule's own timezone (spec 014, US1 scenario 3,
 * FR-017, FR-020; invariants: today is the rule's, never before the
 * auto-confirm start).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
});

it('confirms a due occurrence once with its values, links, the author and cleared false', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'memo' => 'monthly', 'counterparty' => 'Landlord']))->rule;

    freezeClock('2026-11-01 08:00:00');
    $totals = runRecurringJob();
    runRecurringJob();

    $occurrence = occurrencesOf($rule->id)->first();
    $transaction = Transaction::query()->sole();

    expect($totals['confirmed'])->toBe(1)
        ->and($occurrence->status)->toBe('confirmed')
        ->and($occurrence->transaction_id)->toBe($transaction->id)
        ->and($transaction->occurrence_id)->toBe($occurrence->id)
        ->and($transaction->recurring_rule_id)->toBe($rule->id)
        ->and($transaction->created_by)->toBe($this->owner->id)
        ->and($transaction->cleared)->toBeFalse()
        ->and($transaction->amount_minor)->toBe(450000)
        ->and($transaction->date)->toBe('2026-11-01')
        ->and($transaction->description)->toBe('Rent')
        ->and($transaction->memo)->toBe('monthly')
        ->and($transaction->counterparty)->toBe('Landlord')
        ->and($transaction->wallet_id)->toBe($this->wallet->id);
    expect($transaction->id)->toBeUuidV7();
    expectLedgerIntact();
});

it('never confirms a manual rule', function () {
    createRecurringRule($this->owner->id, $this->wallet->id);

    freezeClock('2026-11-02 08:00:00');
    runRecurringJob();

    expect(Transaction::query()->count())->toBe(0);
});

it('uses the rule\'s own timezone for today: Dubai\'s midnight comes two hours before Cairo\'s', function () {
    Profile::query()->where('user_id', $this->world['editor']->id)->update(['timezone' => 'Asia/Dubai']);

    $cairo = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true]))->rule;
    $dubai = createRecurringRule($this->world['editor']->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true]))->rule;

    expect($cairo->timezone)->toBe('Africa/Cairo')
        ->and($dubai->timezone)->toBe('Asia/Dubai');

    // 2026-10-31 20:30 UTC is 2026-11-01 00:30 in Dubai (+4) and 22:30 on the 31st in Cairo (+2).
    freezeClock('2026-10-31 20:30:00');
    runRecurringJob();

    expect(Occurrence::query()->where('recurring_rule_id', $dubai->id)->where('status', 'confirmed')->count())->toBe(1)
        ->and(Occurrence::query()->where('recurring_rule_id', $cairo->id)->where('status', 'confirmed')->count())->toBe(0);

    freezeClock('2026-10-31 22:00:00');
    runRecurringJob();

    expect(Occurrence::query()->where('recurring_rule_id', $cairo->id)->where('status', 'confirmed')->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(2);
});

it('never auto-confirms before the auto-confirm start: a backdated create, an offline-created rule', function () {
    freezeClock('2026-11-05 09:00:00');
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'start_date' => '2026-09-01']))->rule;

    expect(occurrencesOf($rule->id)->pluck('scheduled_date')->all())->toContain('2026-09-01', '2026-10-01', '2026-11-01');

    runRecurringJob();

    expect(Transaction::query()->count())->toBe(0);

    freezeClock('2026-12-01 09:00:00');
    runRecurringJob();

    expect(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->sole()->date)->toBe('2026-12-01');
});

it('confirms oldest first, at most the configured number a pass, and the next pass continues', function () {
    config(['wallets.recurring.max_confirms_per_pass' => 2]);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'frequency' => 'daily', 'anchor_day' => null, 'start_date' => '2026-10-05']))->rule;

    freezeClock('2026-10-09 12:00:00');
    runRecurringJob();

    expect(Transaction::query()->orderBy('date')->pluck('date')->all())->toBe(['2026-10-05', '2026-10-06']);

    runRecurringJob();
    runRecurringJob();

    expect(Transaction::query()->count())->toBe(5)
        ->and(occurrencesOf($rule->id)->where('status', 'confirmed')->count())->toBe(5);
});

it('announces TransactionPosted once per confirmation', function () {
    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true]));
    freezeClock('2026-11-01 08:00:00');
    Event::fake([TransactionPosted::class]);

    runRecurringJob();
    runRecurringJob();

    Event::assertDispatchedTimes(TransactionPosted::class, 1);
    Event::assertDispatched(TransactionPosted::class, fn (TransactionPosted $event): bool => $event->change === 'recorded' && $event->actorUserId === $this->owner->id);
});

it('logs a failing rule and still processes every other one', function () {
    $broken = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true]))->rule;
    $healthy = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'description' => 'Gym']))->rule;
    RecurringRule::query()->whereKey($broken->id)->update(['timezone' => 'Mars/Phobos']);

    freezeClock('2026-11-01 08:00:00');
    Log::spy();
    $totals = runRecurringJob();

    expect($totals['failed'])->toBe(1)
        ->and($totals['confirmed'])->toBe(1)
        ->and(Occurrence::query()->where('recurring_rule_id', $healthy->id)->where('status', 'confirmed')->count())->toBe(1);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['rule_id'] === $broken->id && $context['exception'] === ValueError::class || $context['rule_id'] === $broken->id)->once();
});
