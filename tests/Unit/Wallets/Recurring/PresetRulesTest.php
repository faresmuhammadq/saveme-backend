<?php

use App\Modules\Wallets\Domain\Recurring\RecurringRuleRules;

/*
 * Presets constrain the shape (spec 014, FR-004).
 */

require_once __DIR__.'/RuleHelpers.php';

function gameyaValues(array $changes = []): array
{
    return plainRuleValues([
        'preset' => 'gameya',
        'amount_minor' => 100000,
        'occurrence_count' => 10,
        'payout_round' => 4,
        'payout_category_id' => '0192f7a1-0000-7000-8000-0000000000c6',
        ...$changes,
    ]);
}

function installmentValues(array $changes = []): array
{
    return plainRuleValues([
        'preset' => 'installment',
        'amount_basis' => 'total',
        'amount_minor' => 1000000,
        'occurrence_count' => 12,
        ...$changes,
    ]);
}

it('accepts a well-formed gam\'eya and installment', function (): void {
    expect(RecurringRuleRules::violations(gameyaValues(), null, null))->toBe([])
        ->and(RecurringRuleRules::violations(installmentValues(), null, null))->toBe([])
        ->and(RecurringRuleRules::violations(installmentValues(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => '0192f79e-0000-7000-8000-000000000002']), null, null))->toBe([]);
});

it('forces a gam\'eya to be monthly, every month, an expense with a count and no end date', function (): void {
    $errors = ruleErrors(RecurringRuleRules::violations(gameyaValues(['frequency' => 'weekly', 'anchor_day' => null, 'interval' => 2, 'kind' => 'income', 'end_date' => '2027-12-31', 'occurrence_count' => null]), null, null));

    expect($errors)->toContain('/frequency INVALID_ENUM_VALUE', '/interval INVALID_ENUM_VALUE', '/kind INVALID_ENUM_VALUE', '/end_date INVALID_ENUM_VALUE', '/occurrence_count REQUIRED');
});

it('bounds a preset\'s count to 2 through 120', function (int $count): void {
    expect(ruleErrors(RecurringRuleRules::violations(gameyaValues(['occurrence_count' => $count, 'payout_round' => 1]), null, null)))->toBe(['/occurrence_count OUT_OF_RANGE'])
        ->and(ruleErrors(RecurringRuleRules::violations(installmentValues(['occurrence_count' => $count]), null, null)))->toBe(['/occurrence_count OUT_OF_RANGE']);
})->with([1, 121]);

it('requires a gam\'eya\'s payout round and payout category', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(gameyaValues(['payout_round' => null, 'payout_category_id' => null]), null, null)))->toBe(['/payout_round REQUIRED', '/payout_category_id REQUIRED']);
});

it('refuses a count below the payout round', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(gameyaValues(['occurrence_count' => 3, 'payout_round' => 4]), null, null)))->toBe(['/occurrence_count OUT_OF_RANGE']);
});

it('refuses a gam\'eya payout above 2^53 - 1', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(gameyaValues(['amount_minor' => 9007199254740991]), null, null)))->toBe(['/amount_minor OUT_OF_RANGE']);
});

it('lets an installment be an expense or a transfer, never income, with no payout members', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(installmentValues(['kind' => 'income']), null, null)))->toBe(['/kind INVALID_ENUM_VALUE'])
        ->and(ruleErrors(RecurringRuleRules::violations(installmentValues(['payout_round' => 2, 'payout_category_id' => '0192f7a1-0000-7000-8000-0000000000c6']), null, null)))->toBe(['/payout_round INVALID_ENUM_VALUE', '/payout_category_id INVALID_ENUM_VALUE']);
});

it('refuses payout members on a custom rule', function (): void {
    expect(ruleErrors(RecurringRuleRules::violations(plainRuleValues(['payout_round' => 1, 'payout_category_id' => '0192f7a1-0000-7000-8000-0000000000c6', 'occurrence_count' => 5]), null, null)))->toBe(['/payout_round INVALID_ENUM_VALUE', '/payout_category_id INVALID_ENUM_VALUE']);
});
