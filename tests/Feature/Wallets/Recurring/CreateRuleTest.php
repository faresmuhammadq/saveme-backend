<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use Illuminate\Support\Str;

/*
 * Create a rule (spec 014, US1 scenarios 1, 4, 5; FR-001 to FR-004, FR-011).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
});

it('creates the rule at version 1 with the author\'s timezone and materialises through the horizon', function () {
    $view = createRecurringRule($this->owner->id, $this->wallet->id);

    $rule = $view->rule;
    $occurrences = occurrencesOf($rule->id);

    expect($rule->version)->toBe(1)
        ->and($rule->status->value)->toBe('active')
        ->and($rule->timezone)->toBe('Africa/Cairo')
        ->and($rule->createdBy)->toBe($this->owner->id)
        ->and($rule->autoConfirmFrom)->toBe('2026-10-05')
        ->and($occurrences->pluck('scheduled_date')->all())->toBe(['2026-11-01'])
        ->and($occurrences->first()->status)->toBe('scheduled')
        ->and($occurrences->first()->amount_minor)->toBe(450000)
        ->and($occurrences->first()->ordinal)->toBe(1)
        ->and($occurrences->first()->version)->toBe(1);
});

it('uses the configured default timezone when the author has no profile', function () {
    Profile::query()->where('user_id', $this->owner->id)->delete();

    $view = createRecurringRule($this->owner->id, $this->wallet->id);

    expect($view->rule->timezone)->toBe(config('identity.default_timezone'));
});

it('materialises at least the next occurrence of a yearly rule', function () {
    $view = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'yearly', 'anchor_day' => 15, 'start_date' => '2026-03-15']));

    expect(occurrencesOf($view->rule->id)->pluck('scheduled_date')->all())->toBe(['2027-03-15']);
});

it('backfills at most 90 days before the creation day and counts the rest as rounds', function () {
    $view = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['start_date' => '2026-01-01', 'occurrence_count' => 12]));
    $occurrences = occurrencesOf($view->rule->id);

    // 2026-10-05 minus 90 days is 2026-07-07: August 1st is the first round in the window.
    expect($occurrences->first()->scheduled_date)->toBe('2026-08-01')
        ->and($occurrences->first()->ordinal)->toBe(8)
        ->and($occurrences->pluck('scheduled_date')->all())->toBe(['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01']);
});

it('creates at most 400 occurrences a pass and the next pass continues', function () {
    config(['wallets.recurring.backfill_days' => 2000]);
    $view = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'daily', 'anchor_day' => null, 'start_date' => '2024-01-01']));

    expect(occurrencesOf($view->rule->id))->toHaveCount(400);

    app(RecurringRuleService::class)->catchUp($view->rule->id);

    expect(occurrencesOf($view->rule->id))->toHaveCount(800);
});

it('replays a create: no second rule, no second occurrence, nothing bumped', function () {
    $id = (string) Str::uuid7();
    $first = saveRecurringRule($this->owner->id, $this->wallet->id, $id, recurringRuleBody());
    $second = saveRecurringRule($this->owner->id, $this->wallet->id, $id, recurringRuleBody());

    expect(RecurringRule::query()->count())->toBe(1)
        ->and(Occurrence::query()->count())->toBe(1)
        ->and($second->rule->version)->toBe(1)
        ->and($second->rule->changeSeq)->toBe($first->rule->changeSeq);
});

it('respects an end date and a count', function () {
    $ended = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-05', 'end_date' => '2026-10-19']));
    $counted = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-05', 'occurrence_count' => 2]));

    expect(occurrencesOf($ended->rule->id)->pluck('scheduled_date')->all())->toBe(['2026-10-05', '2026-10-12', '2026-10-19'])
        ->and(occurrencesOf($counted->rule->id)->pluck('scheduled_date')->all())->toBe(['2026-10-05', '2026-10-12']);
});

it('creates a paused rule without materialising', function () {
    $view = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['status' => 'paused']));

    expect($view->rule->status->value)->toBe('paused')
        ->and(occurrencesOf($view->rule->id))->toHaveCount(0);
});

it('trims texts and stores a blank one as null', function () {
    $view = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['description' => '  Rent  ', 'memo' => '   ', 'counterparty' => ' Landlord ']));

    expect($view->rule->description)->toBe('Rent')
        ->and($view->rule->memo)->toBeNull()
        ->and($view->rule->counterparty)->toBe('Landlord');
});

it('accepts the caller\'s own and a platform category, archived too, and publishes the reference of a person\'s', function () {
    $own = ownedCategory($this->owner->id, ['kind' => 'expense']);
    $archived = ownedCategory($this->owner->id, ['kind' => 'expense', 'archived_at' => now()]);

    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $own->id]));
    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $archived->id]));
    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody());

    expect(CategoryReference::query()->where('wallet_id', $this->wallet->id)->whereNull('deleted_at')->pluck('category_id')->sort()->values()->all())
        ->toBe(collect([$own->id, $archived->id])->sort()->values()->all());
});

it('refuses a foreign, deleted or wrong-kind category at /category_id', function (string $case) {
    $other = person('other@example.test');
    $category = match ($case) {
        'foreign' => ownedCategory($other->id, ['kind' => 'expense']),
        'deleted' => ownedCategory($this->owner->id, ['kind' => 'expense', 'deleted_at' => now()]),
        'wrong kind' => ownedCategory($this->owner->id, ['kind' => 'income']),
    };

    $refusal = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $category->id])));

    expect(fieldErrorsOf($refusal))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(RecurringRule::query()->count())->toBe(0);
})->with(['foreign', 'deleted', 'wrong kind']);

it('refuses a currency that is not the wallet\'s', function () {
    $refusal = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['currency' => 'USD'])));

    expect(fieldErrorsOf($refusal))->toBe(['/currency CURRENCY_MISMATCH'])
        ->and($refusal->errorCode)->toBe(ErrorCode::ValidationFailed);
});

it('stamps every row from the wallet counter and leaves the wallet\'s the highest', function () {
    $view = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-05']));
    $stamps = [$view->rule->changeSeq, ...occurrencesOf($view->rule->id)->pluck('change_seq')->all()];

    expect(array_unique($stamps))->toHaveCount(count($stamps))
        ->and((int) $this->wallet->fresh()->change_seq)->toBeGreaterThan(max($stamps));
});

it('refuses a category of nobody\'s: not a uuid known to anyone', function () {
    $refusal = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => (string) Str::uuid7()])));

    expect(fieldErrorsOf($refusal))->toBe(['/category_id INVALID_ENUM_VALUE']);
});
