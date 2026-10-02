<?php

use App\Modules\Wallets\Infrastructure\Models\CategoryReference;

/*
 * Gam'eya and installment (spec 014, US4 scenarios 1 to 4; FR-004, FR-012).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
    config(['wallets.recurring.horizon_days' => 400]);
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function gameyaBody(array $changes = []): array
{
    return recurringRuleBody([
        'preset' => 'gameya', 'amount_minor' => 100000, 'description' => 'Gam\'eya', 'anchor_day' => 5, 'start_date' => '2026-10-05',
        'occurrence_count' => 10, 'payout_round' => 4, 'payout_category_id' => platformCategoryId('income'), ...$changes,
    ]);
}

it('materialises a gam\'eya\'s contributions and one payout on round 4\'s date worth the ten of them', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody())->rule;
    $rows = occurrencesOf($rule->id);
    $payout = $rows->firstWhere('is_payout', true);
    $roundFour = $rows->where('is_payout', false)->firstWhere('ordinal', 4);

    expect($rows->where('is_payout', false))->toHaveCount(10)
        ->and($rows->where('is_payout', true))->toHaveCount(1)
        ->and($rows->where('is_payout', false)->pluck('kind')->unique()->all())->toBe(['expense'])
        ->and($rows->where('is_payout', false)->pluck('amount_minor')->unique()->all())->toBe([100000])
        ->and($payout->kind)->toBe('income')
        ->and($payout->category_id)->toBe(platformCategoryId('income'))
        ->and($payout->amount_minor)->toBe(1000000)
        ->and($payout->ordinal)->toBe(4)
        ->and($payout->scheduled_date)->toBe($roundFour->scheduled_date)
        ->and($payout->id)->not->toBe($roundFour->id);
});

it('derives the payout from the scheduled rounds, skips and edits ignored', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody())->rule;
    $first = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, occurrenceBody($first, ['status' => 'skipped', 'amount_minor' => 5]));

    expect(occurrencesOf($rule->id)->firstWhere('is_payout', true)->amount_minor)->toBe(1000000);
});

it('follows a contribution change before any round is resolved, and freezes the contribution after', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody())->rule;

    saveRecurringRule($this->owner->id, $this->wallet->id, $rule->id, [...gameyaBody(['amount_minor' => 200000]), 'version' => 1]);

    expect(occurrencesOf($rule->id)->firstWhere('is_payout', true)->amount_minor)->toBe(2000000);

    $first = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $first->id, occurrenceBody($first, ['status' => 'skipped']));

    $refusal = refusalOf(fn () => saveRecurringRule($this->owner->id, $this->wallet->id, $rule->id, [...gameyaBody(['amount_minor' => 300000]), 'version' => 2]));

    expect(fieldErrorsOf($refusal))->toBe(['/amount_minor IMMUTABLE_FIELD'])
        ->and(occurrencesOf($rule->id)->firstWhere('is_payout', true)->amount_minor)->toBe(2000000);
});

it('splits an installment total exactly: four of 83,334 and eight of 83,333', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody([
        'preset' => 'installment', 'amount_basis' => 'total', 'amount_minor' => 1000000, 'description' => 'Fridge', 'counterparty' => 'B.TECH',
        'auto_confirm' => true, 'anchor_day' => 25, 'start_date' => '2026-10-25', 'occurrence_count' => 12,
    ]))->rule;

    $amounts = occurrencesOf($rule->id)->pluck('amount_minor')->all();

    expect($amounts)->toBe([...array_fill(0, 4, 83334), ...array_fill(0, 8, 83333)])
        ->and(array_sum($amounts))->toBe(1000000);
});

it('creates an installment as a transfer', function () {
    $destination = ownedWallet($this->owner->id);

    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody([
        'preset' => 'installment', 'kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id,
        'amount_minor' => 300000, 'amount_basis' => 'total', 'start_date' => '2026-10-25', 'anchor_day' => 25, 'occurrence_count' => 3,
    ]))->rule;

    expect(occurrencesOf($rule->id)->pluck('amount_minor')->all())->toBe([100000, 100000, 100000])
        ->and(occurrencesOf($rule->id)->pluck('counter_wallet_id')->unique()->all())->toBe([$destination->id]);
});

it('refuses every preset violation at its member', function () {
    $weekly = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody(['frequency' => 'weekly', 'anchor_day' => null])));
    $noPayout = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody(['payout_round' => null, 'payout_category_id' => null])));
    $income = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody(['payout_category_id' => platformCategoryId('expense')])));
    $tooBig = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody(['amount_minor' => 9007199254740991])));
    $round = refusalOf(fn () => createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody(['occurrence_count' => 3])));

    expect(fieldErrorsOf($weekly))->toBe(['/frequency INVALID_ENUM_VALUE'])
        ->and(fieldErrorsOf($noPayout))->toBe(['/payout_round REQUIRED', '/payout_category_id REQUIRED'])
        ->and(fieldErrorsOf($income))->toBe(['/payout_category_id INVALID_ENUM_VALUE'])
        ->and(fieldErrorsOf($tooBig))->toBe(['/amount_minor OUT_OF_RANGE'])
        ->and(fieldErrorsOf($round))->toBe(['/occurrence_count OUT_OF_RANGE']);
});

it('publishes the reference of a person\'s payout category', function () {
    $payout = ownedCategory($this->owner->id, ['kind' => 'income']);

    createRecurringRule($this->owner->id, $this->wallet->id, gameyaBody(['payout_category_id' => $payout->id]));

    expect(CategoryReference::query()->where('wallet_id', $this->wallet->id)->where('category_id', $payout->id)->whereNull('deleted_at')->count())->toBe(1);
});
