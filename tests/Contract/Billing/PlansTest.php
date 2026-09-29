<?php

use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * GET /plans (spec 009, US2): public, localised, money prices, no code.
 */

test('anyone reads the active plans in order, without a code', function () {
    $response = $this->withHeaders(jsonHeaders())->get('/api/v1/plans')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.limit', 50);

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Free', 'Plus · Monthly', 'Plus · Yearly'])
        ->and($response->json('data.1.prices'))->toEqual([['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 14900, 'currency' => 'EGP']])
        ->and($response->getContent())->not->toContain('"code"');
});

test('names follow the requested language', function () {
    $response = $this->withHeaders(jsonHeaders(['Accept-Language' => 'ar']))->get('/api/v1/plans');

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['مجاني', 'بلس · شهري', 'بلس · سنوي']);
});

test('a retired plan leaves the catalogue but keeps serving its subscribers', function () {
    person();
    $credential = signInAs()->json('data.credential');
    $userId = User::query()->sole()->id;
    Subscription::factory()->forPlan('plus_yearly')->create(['user_id' => $userId]);
    Plan::query()->where('code', 'plus_yearly')->update(['status' => 'retired']);

    expect($this->withHeaders(jsonHeaders())->get('/api/v1/plans')->json('data'))->toHaveCount(2);
    $this->flushHeaders();

    $response = $this->withHeaders(asCredential($credential))->get('/api/v1/me/entitlements');
    expect($response->json('data.features')['sharing.enabled'])->toBeTrue();
});
