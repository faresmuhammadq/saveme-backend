<?php

use App\Modules\Sync\Application\TombstonePurgeRun;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\DB;

/*
 * The tombstone purge and rules and occurrences (spec 014, US5 scenario 5,
 * FR-025): removed after 90 days with the wallet's horizon raised, except a
 * rule while any of its occurrences exists and an occurrence while a
 * transaction refers to it.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
});

function purgeNow(): array
{
    return app(TombstonePurgeRun::class)->handle();
}

it('purges occurrence tombstones, then the rule\'s, past 90 days and raises the wallet\'s horizon', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $rule->id, 1, presentRule());
    $highest = (int) max(RecurringRule::query()->whereKey($rule->id)->value('change_seq'), occurrencesOf($rule->id, true)->max('change_seq'));

    $this->travel(91)->days();
    $removed = purgeNow();

    expect($removed['occurrences'])->toBeGreaterThan(0)
        ->and($removed['recurring_rules'])->toBe(1)
        ->and(Occurrence::query()->count())->toBe(0)
        ->and(RecurringRule::query()->count())->toBe(0)
        ->and((int) DB::table('wallets')->where('id', $this->wallet->id)->value('purged_change_seq'))->toBe($highest);
});

it('keeps younger tombstones', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id)->rule;
    app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $rule->id, 1, presentRule());

    $this->travel(30)->days();
    $removed = purgeNow();

    expect($removed['occurrences'])->toBe(0)->and($removed['recurring_rules'])->toBe(0)
        ->and(RecurringRule::query()->count())->toBe(1);
});

it('keeps a rule tombstone while any of its occurrences exists', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $rule->id, 1, presentRule());
    // An occurrence that a transaction still names cannot go, so its rule stays too.
    $transaction = Transaction::factory()->create(['wallet_id' => $this->wallet->id, 'category_id' => $occurrence->category_id, 'recurring_rule_id' => $rule->id, 'occurrence_id' => $occurrence->id]);

    $this->travel(91)->days();
    $removed = purgeNow();

    expect($removed['recurring_rules'])->toBe(0)
        ->and(Occurrence::query()->whereKey($occurrence->id)->exists())->toBeTrue()
        ->and(RecurringRule::query()->whereKey($rule->id)->exists())->toBeTrue();

    $transaction->delete();
    purgeNow();

    expect(Occurrence::query()->count())->toBe(0)->and(RecurringRule::query()->count())->toBe(0);
});

it('does not purge a live rule or live occurrences, nor a wallet or category they use', function () {
    $category = ownedCategory($this->owner->id, ['kind' => 'expense']);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $category->id]))->rule;

    $this->travel(120)->days();
    purgeNow();

    expect(RecurringRule::query()->whereKey($rule->id)->exists())->toBeTrue()
        ->and(Occurrence::query()->count())->toBeGreaterThan(0);
});
