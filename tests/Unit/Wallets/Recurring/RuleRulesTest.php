<?php

use App\Modules\Wallets\Domain\Recurring\RecurringRuleRules;

require_once __DIR__.'/RuleHelpers.php';

it('accepts a plain monthly rule', function (): void {
    expect(RecurringRuleRules::violations(plainRuleValues(), null, null))->toBe([]);
});

it('refuses ended on create', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['status' => 'ended']), null, null)))->toBe(['/status INVALID_ENUM_VALUE']);
});

it('refuses a change to the preset, kind, currency and counter wallet', function (): void {
    $stored = ruleRecord();
    $changed = plainRuleValues(['preset' => 'installment', 'kind' => 'income', 'currency' => 'USD', 'counter_wallet_id' => '0192f79e-0000-7000-8000-000000000002', 'occurrence_count' => 6]);

    expect(ruleErrors(RecurringRuleRules::violations($changed, $stored, null)))->toContain('/preset IMMUTABLE_FIELD', '/kind IMMUTABLE_FIELD', '/currency IMMUTABLE_FIELD', '/counter_wallet_id IMMUTABLE_FIELD');
});

it('freezes the schedule members once an occurrence is resolved', function (): void {
    $stored = ruleRecord();
    $resolved = ['ordinal' => 1, 'scheduled_date' => '2026-11-01'];
    $changed = plainRuleValues(['frequency' => 'weekly', 'anchor_day' => null, 'interval' => 2, 'start_date' => '2026-11-02', 'amount_basis' => 'total', 'occurrence_count' => 12]);

    expect(ruleErrors(RecurringRuleRules::violations($changed, $stored, $resolved)))->toContain('/frequency IMMUTABLE_FIELD', '/interval IMMUTABLE_FIELD', '/anchor_day IMMUTABLE_FIELD', '/start_date IMMUTABLE_FIELD', '/amount_basis IMMUTABLE_FIELD');
    expect(RecurringRuleRules::violations(plainRuleValues(['amount_minor' => 500000]), $stored, $resolved))->toBe([]);
});

it('freezes the count of a gam\'eya and of a total rule once resolved, not of a plain one', function (): void {
    $resolved = ['ordinal' => 1, 'scheduled_date' => '2026-11-01'];
    $gameya = ruleRecord(['preset' => 'gameya', 'occurrence_count' => 10, 'payout_round' => 4, 'payout_category_id' => '0192f7a1-0000-7000-8000-0000000000c6']);
    $total = ruleRecord(['amount_basis' => 'total', 'occurrence_count' => 10]);
    $plain = ruleRecord(['occurrence_count' => 10]);

    $gameyaChange = plainRuleValues(['preset' => 'gameya', 'occurrence_count' => 12, 'payout_round' => 4, 'payout_category_id' => '0192f7a1-0000-7000-8000-0000000000c6']);

    expect(ruleErrors(RecurringRuleRules::violations($gameyaChange, $gameya, $resolved)))->toContain('/occurrence_count IMMUTABLE_FIELD')
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['amount_basis' => 'total', 'occurrence_count' => 12]), $total, $resolved)))->toBe(['/occurrence_count IMMUTABLE_FIELD'])
        ->and(RecurringRuleRules::violations(plainRuleValues(['occurrence_count' => 12]), $plain, $resolved))->toBe([]);
});

it('freezes the amount of a gam\'eya and of a total rule once resolved, so shares and payout keep summing, not of a plain one', function (): void {
    $resolved = ['ordinal' => 1, 'scheduled_date' => '2026-11-01'];
    $payoutCategory = '0192f7a1-0000-7000-8000-0000000000c6';
    $gameya = ruleRecord(['preset' => 'gameya', 'occurrence_count' => 10, 'payout_round' => 4, 'payout_category_id' => $payoutCategory]);
    $total = ruleRecord(['amount_basis' => 'total', 'occurrence_count' => 10]);
    $plain = ruleRecord(['occurrence_count' => 10]);
    $amount = $plain->writableValues()['amount_minor'] + 1;

    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['preset' => 'gameya', 'occurrence_count' => 10, 'payout_round' => 4, 'payout_category_id' => $payoutCategory, 'amount_minor' => $amount]), $gameya, $resolved)))->toBe(['/amount_minor IMMUTABLE_FIELD'])
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['amount_basis' => 'total', 'occurrence_count' => 10, 'amount_minor' => $amount]), $total, $resolved)))->toBe(['/amount_minor IMMUTABLE_FIELD'])
        ->and(RecurringRuleRules::violations(plainRuleValues(['amount_basis' => 'total', 'occurrence_count' => 10, 'amount_minor' => $amount]), $total, null))->toBe([])
        ->and(RecurringRuleRules::violations(plainRuleValues(['occurrence_count' => 10, 'amount_minor' => $amount]), $plain, $resolved))->toBe([]);
});

it('refuses an end that would cut off a resolved occurrence', function (): void {
    $stored = ruleRecord();
    $resolved = ['ordinal' => 3, 'scheduled_date' => '2027-01-01'];

    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['end_date' => '2026-12-31']), $stored, $resolved)))->toBe(['/end_date OUT_OF_RANGE'])
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['occurrence_count' => 2]), $stored, $resolved)))->toBe(['/occurrence_count OUT_OF_RANGE'])
        ->and(RecurringRuleRules::violations(plainRuleValues(['end_date' => '2027-01-01']), $stored, $resolved))->toBe([]);
});

it('refuses every change to an ended rule', function (): void {
    $stored = ruleRecord(['status' => 'ended']);

    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['status' => 'ended', 'amount_minor' => 1, 'description' => 'x']), $stored, null)))->toBe(['/amount_minor IMMUTABLE_FIELD', '/description IMMUTABLE_FIELD'])
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['status' => 'active']), $stored, null)))->toBe(['/status IMMUTABLE_FIELD']);
});

it('puts an anchor day only on monthly and yearly rules', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['frequency' => 'daily']), null, null)))->toBe(['/anchor_day INVALID_ENUM_VALUE'])
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['anchor_day' => null]), null, null)))->toBe(['/anchor_day REQUIRED'])
        ->and(RecurringRuleRules::violations(plainRuleValues(['frequency' => 'weekly', 'anchor_day' => null]), null, null))->toBe([]);
});

it('allows at most one of the end date and the count', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['end_date' => '2027-01-01', 'occurrence_count' => 3]), null, null)))->toBe(['/occurrence_count INVALID_ENUM_VALUE']);
});

it('refuses an end date before the start', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['end_date' => '2026-10-31']), null, null)))->toBe(['/end_date OUT_OF_RANGE']);
});

it('needs a count for a total and a total of at least the count', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['amount_basis' => 'total']), null, null)))->toBe(['/amount_basis INVALID_ENUM_VALUE'])
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['amount_basis' => 'total', 'occurrence_count' => 12, 'amount_minor' => 11]), null, null)))->toBe(['/amount_minor OUT_OF_RANGE'])
        ->and(RecurringRuleRules::violations(plainRuleValues(['amount_basis' => 'total', 'occurrence_count' => 12, 'amount_minor' => 12]), null, null))->toBe([]);
});

it('wants a category on income and expense and a destination on a transfer', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['category_id' => null]), null, null)))->toBe(['/category_id REQUIRED'])
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['counter_wallet_id' => '0192f79e-0000-7000-8000-000000000002']), null, null)))->toBe(['/counter_wallet_id INVALID_ENUM_VALUE'])
        ->and(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['kind' => 'transfer', 'category_id' => '0192f7a1-0000-7000-8000-0000000000c5']), null, null)))->toBe(['/category_id INVALID_ENUM_VALUE', '/counter_wallet_id REQUIRED']);
});

it('collects several refusals at once, one entry per member', function (): void {
    $errors = ruleErrors(RecurringRuleRules::violations(plainRuleValues(['status' => 'ended', 'frequency' => 'daily', 'end_date' => '2027-01-01', 'occurrence_count' => 3, 'category_id' => null]), null, null));

    expect($errors)->toContain('/status INVALID_ENUM_VALUE', '/anchor_day INVALID_ENUM_VALUE', '/occurrence_count INVALID_ENUM_VALUE', '/category_id REQUIRED')
        ->and($errors)->toBe(array_values(array_unique($errors)));
});
