<?php

use App\Modules\Billing\Application\Claims\ClaimFilter;
use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use Illuminate\Support\Str;

/*
 * The paged claim list, the awaiting summary and the address-free review
 * state operators' screens read (spec 021, FR-015, FR-016, FR-017).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->query = app(ClaimQuery::class);
});

function ids(array $items): array
{
    return array_map(fn ($item) => $item->id, $items);
}

test('submitted claims list oldest first, the other statuses newest first', function () {
    $newer = PaymentClaim::factory()->create(['created_at' => now()->subHour()]);
    $older = PaymentClaim::factory()->create(['created_at' => now()->subHours(3)]);
    $approvedOld = PaymentClaim::factory()->approved()->create(['created_at' => now()->subHours(5)]);
    $approvedNew = PaymentClaim::factory()->approved()->create(['created_at' => now()->subHours(2)]);

    expect(ids($this->query->page(ClaimFilter::Submitted, null, null)->items))->toBe([$older->id, $newer->id])
        ->and(ids($this->query->page(ClaimFilter::Approved, null, null)->items))->toBe([$approvedNew->id, $approvedOld->id])
        ->and($this->query->page(ClaimFilter::Rejected, null, null)->items)->toBe([])
        ->and($this->query->page(ClaimFilter::Expired, null, null)->items)->toBe([]);
});

test('the submitted list leaves out claims past their expiry', function () {
    $live = PaymentClaim::factory()->create();
    PaymentClaim::factory()->create(['expires_at' => now()->subMinute()]);

    expect(ids($this->query->page(ClaimFilter::Submitted, null, null)->items))->toBe([$live->id]);
});

test('pages hold 25 and the cursor walks every claim once in order', function () {
    $claims = PaymentClaim::factory()->count(30)
        ->sequence(...array_map(fn (int $n) => ['created_at' => now()->subMinutes(100 - $n)], range(1, 30)))->create();

    $first = $this->query->page(ClaimFilter::Submitted, null, null);
    $second = $this->query->page(ClaimFilter::Submitted, null, $first->next);

    expect($first->items)->toHaveCount(25)
        ->and($first->next)->not->toBeNull()
        ->and($second->items)->toHaveCount(5)
        ->and($second->next)->toBeNull()
        ->and([...ids($first->items), ...ids($second->items)])->toBe($claims->pluck('id')->all());
});

test('a malformed cursor starts from the top', function () {
    $claim = PaymentClaim::factory()->create();

    expect(ids($this->query->page(ClaimFilter::Submitted, null, 'not-a-cursor')->items))->toBe([$claim->id]);
});

test('a reference search ignores spaces, hyphens and case', function () {
    $hit = PaymentClaim::factory()->reference('ABCD12345678')->create();
    PaymentClaim::factory()->reference('ZZZZ99999999')->create();

    foreach (['abcd 1234-5678', 'ABCD12345678', ' abcd-1234 5678 '] as $typed) {
        expect(ids($this->query->page(ClaimFilter::Submitted, ['reference' => $typed], null)->items))->toBe([$hit->id]);
    }
});

test('an address search matches by lookup value and no item carries an address', function () {
    $hit = PaymentClaim::factory()->forAddress('Mona@Example.test')->create();
    PaymentClaim::factory()->forAddress('other@example.test')->create();

    $lookup = $this->query->lookupFor(' MONA@example.test ');
    $page = $this->query->page(ClaimFilter::Submitted, ['lookup' => $lookup], null);

    expect(ids($page->items))->toBe([$hit->id])
        ->and(json_encode($page))->not->toContain('example.test')->not->toContain('v1.');
    expect($this->query->lookupFor('not an address'))->toBeNull();
});

test('items carry the plan names in both languages and the duplicate count', function () {
    $plus = Plan::query()->where('code', 'plus_yearly')->firstOrFail();
    PaymentClaim::factory()->reference('SAMEREF00001')->create();
    PaymentClaim::factory()->reference('SAMEREF00001')->create();

    $item = $this->query->page(ClaimFilter::Submitted, null, null)->items[0];

    expect($item->planName)->toBe(['en' => $plus->name['en'], 'ar' => $plus->name['ar']])
        ->and($item->duplicateCount)->toBe(1);
});

test('the awaiting summary counts submitted unexpired claims and the oldest instant', function () {
    PaymentClaim::factory()->create(['created_at' => now()->subHours(3)]);
    PaymentClaim::factory()->create(['created_at' => now()->subHour()]);
    PaymentClaim::factory()->create(['expires_at' => now()->subMinute(), 'created_at' => now()->subDays(9)]);
    PaymentClaim::factory()->approved()->create();

    $summary = $this->query->awaitingSummary();

    expect($summary['count'])->toBe(2)
        ->and($summary['oldest']->format('c'))->toBe(now()->subHours(3)->format('c'));

    PaymentClaim::query()->delete();
    expect($this->query->awaitingSummary())->toBe(['count' => 0, 'oldest' => null]);
});

test('the review state has the review facts and no address member', function () {
    $claim = PaymentClaim::factory()->rejected('amount_mismatch', 'checked the app')->create();

    $state = $this->query->reviewState($claim->id);

    expect($state->status)->toBe('rejected')
        ->and($state->rejectReason)->toBe('amount_mismatch')
        ->and($state->reviewNote)->toBe('checked the app')
        ->and($state->reviewedBy)->toBe($claim->reviewed_by)
        ->and($state->closedAt)->not->toBeNull()
        ->and(property_exists($state, 'address'))->toBeFalse()
        ->and(json_encode($state))->not->toContain('example.test')
        ->and($this->query->reviewState((string) Str::uuid7()))->toBeNull();
});

test('the detail says whether the claim still equals the plan price', function () {
    $claim = PaymentClaim::factory()->create();

    expect($this->query->detail($claim->id)->matchesCurrentPrice)->toBeTrue();

    $plan = Plan::query()->findOrFail($claim->plan_id);
    $prices = $plan->prices;
    $prices[0]['amount_minor'] = 150000;
    $plan->forceFill(['prices' => $prices])->save();

    expect($this->query->detail($claim->id)->matchesCurrentPrice)->toBeFalse();
});
