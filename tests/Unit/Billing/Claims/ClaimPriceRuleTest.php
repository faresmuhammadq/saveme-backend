<?php

use App\Modules\Billing\Domain\Claims\ClaimPrice;
use App\Modules\Billing\Domain\PlanCode;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Shared\Domain\Errors\ErrorCode;

/*
 * What a claim must say about money (spec 020, FR-004, FR-005; P-03).
 */

function claimPlan(array $prices, string $status = 'active', bool $isDefault = false): PlanRecord
{
    return new PlanRecord('plan-1', new PlanCode('plus_yearly'), ['en' => 'Plus', 'ar' => 'بلس'], 'yearly', null, $isDefault, $status, 1, [], $prices);
}

function claimErrors(?PlanRecord $plan, int $amount, string $currency): array
{
    return array_map(fn ($e) => [$e->pointer, $e->code], ClaimPrice::check($plan, $amount, $currency));
}

$instapay = [['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 129000, 'currency' => 'EGP']];

test('exactly the InstaPay price is accepted', function () use ($instapay) {
    expect(claimErrors(claimPlan($instapay), 129000, 'EGP'))->toBe([]);
});

test('an amount one minor unit off is refused at /amount_minor', function (int $amount) use ($instapay) {
    expect(claimErrors(claimPlan($instapay), $amount, 'EGP'))->toBe([['/amount_minor', ErrorCode::OutOfRange]]);
})->with([128999, 129001, 0]);

test('another currency is refused at /currency', function () use ($instapay) {
    expect(claimErrors(claimPlan($instapay), 129000, 'USD'))->toBe([['/currency', ErrorCode::CurrencyMismatch]]);
});

test('both faults are reported together', function () use ($instapay) {
    expect(claimErrors(claimPlan($instapay), 1, 'USD'))->toBe([['/currency', ErrorCode::CurrencyMismatch], ['/amount_minor', ErrorCode::OutOfRange]]);
});

test('a plan that cannot be claimed is refused at /plan_id, whatever the amount', function (?PlanRecord $plan) {
    expect(claimErrors($plan, 129000, 'EGP'))->toBe([['/plan_id', ErrorCode::InvalidEnumValue]]);
})->with([
    'unknown plan' => [null],
    'no InstaPay price' => [fn () => claimPlan([['channel' => 'google_play', 'product_id' => 'x', 'amount_minor' => 129000, 'currency' => 'EGP']])],
    'no price at all' => [fn () => claimPlan([])],
    'retired' => [fn () => claimPlan([['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 129000, 'currency' => 'EGP']], 'retired')],
    'the default plan' => [fn () => claimPlan([['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 129000, 'currency' => 'EGP']], 'active', true)],
]);
