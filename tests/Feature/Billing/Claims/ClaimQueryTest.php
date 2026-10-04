<?php

use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use Illuminate\Support\Str;

/*
 * What operators read about claims (spec 020, FR-009, FR-011, FR-045).
 */

beforeEach(function () {
    $this->freezeTime();
});

test('the queue lists submitted claims first, oldest first, with duplicate counts and no address', function () {
    $oldest = PaymentClaim::factory()->forAddress('a@example.test')->reference('SAMEREF00001')->create(['created_at' => now()->subHours(3)]);
    $middle = PaymentClaim::factory()->forAddress('b@example.test')->reference('SAMEREF00001')->create(['created_at' => now()->subHours(2)]);
    $newest = PaymentClaim::factory()->forAddress('c@example.test')->reference('OTHERREF0001')->create(['created_at' => now()->subHour()]);
    $closed = PaymentClaim::factory()->forAddress('d@example.test')->rejected()->create(['created_at' => now()->subHours(5)]);

    $queue = app(ClaimQuery::class)->queue();

    expect(array_map(fn ($c) => $c->id, $queue))->toBe([$oldest->id, $middle->id, $newest->id, $closed->id])
        ->and(array_map(fn ($c) => $c->duplicateCount, $queue))->toBe([1, 1, 0, 0])
        ->and(array_map(fn ($c) => $c->status, $queue))->toBe(['submitted', 'submitted', 'submitted', 'rejected'])
        ->and(json_encode($queue))->not->toContain('example.test')->not->toContain('v1.b1.');
});

test('the queue pages by the last id seen', function () {
    $claims = PaymentClaim::factory()->count(5)->sequence(...array_map(fn (int $n) => ['created_at' => now()->subMinutes(10 - $n)], range(1, 5)))->create();
    $query = app(ClaimQuery::class);

    $first = $query->queue(null, 2);
    $second = $query->queue($first[1]->id, 2);
    $third = $query->queue($second[1]->id, 2);

    expect(array_map(fn ($c) => $c->id, [...$first, ...$second, ...$third]))->toBe($claims->pluck('id')->all());
});

test('the detail opens the address and says whether an account holds it', function () {
    $registered = PaymentClaim::factory()->forAddress('ada@example.test')->withReceipt()->create();
    $unregistered = PaymentClaim::factory()->forAddress('nobody@example.test')->create();
    person('ada@example.test');
    $query = app(ClaimQuery::class);

    $withAccount = $query->detail($registered->id);
    $without = $query->detail($unregistered->id);

    expect($withAccount->address)->toBe('ada@example.test')
        ->and($withAccount->accountExists)->toBeTrue()
        ->and($withAccount->hasReceipt)->toBeTrue()
        ->and($withAccount->receiptMediaType)->toBe('image/png')
        ->and($withAccount->locale)->toBe('en')
        ->and($without->address)->toBe('nobody@example.test')
        ->and($without->accountExists)->toBeFalse()
        ->and($without->hasReceipt)->toBeFalse()
        ->and($query->detail((string) Str::uuid7()))->toBeNull();
});

test('the detail carries the review facts, the code state and the other claims with the reference', function () {
    $claim = PaymentClaim::factory()->reference('SAMEREF00001')->approved()->create();
    $other = PaymentClaim::factory()->reference('SAMEREF00001')->create();
    RedeemCode::factory()->state(['claim_id' => $claim->id])->create();

    $detail = app(ClaimQuery::class)->detail($claim->id);

    expect($detail->summary->status)->toBe('approved')
        ->and($detail->reviewedBy)->toBe($claim->reviewed_by)
        ->and($detail->codeStatus)->toBe('issued')
        ->and($detail->codeExpiresAt->format('c'))->toBe(now()->addDays(30)->format('c'))
        ->and(array_map(fn ($c) => $c->id, $detail->duplicates))->toBe([$other->id])
        ->and($detail->summary->duplicateCount)->toBe(1);
});

test('after erasure the detail has no address and no account is reported', function () {
    $claim = PaymentClaim::factory()->closedDaysAgo(100)->create();
    PaymentClaim::query()->whereKey($claim->id)->update(['address_sealed' => null, 'address_lookup' => null, 'erased_at' => now()]);

    $detail = app(ClaimQuery::class)->detail($claim->id);

    expect($detail->address)->toBeNull()->and($detail->accountExists)->toBeFalse()->and($detail->erasedAt)->not->toBeNull();
});
