<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/reports/by-category (spec 019, FR-011, FR-012): every
 * documented status. The schema forbids extra members, so a share would fail
 * the 200 validation.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->uri = '/wallets/'.$this->wallet->id.'/reports/by-category';
});

test('200 carries items, totals and no share, with meta an empty object', function () {
    $groceries = platformCategoryId();
    $gym = ownedCategory($this->user->id, ['name' => 'Gym']);
    spend($this->user->id, $this->wallet->id, 300000, ['category_id' => $groceries]);
    spend($this->user->id, $this->wallet->id, 80000, ['category_id' => $gym->id]);
    recordTransaction($this->user->id, $this->wallet->id, incomeBody(['amount_minor' => 900000]));

    $response = reportCall($this->credential, $this->uri)->assertValidRequest()->assertValidResponse(200);
    $key = Category::query()->whereKey($groceries)->value('system_key');
    $salary = Category::query()->whereKey(platformCategoryId('income'))->value('system_key');

    expect($response->json('data.expenses'))->toBe([
        'total' => wireMoney(380000),
        'planned' => null,
        'items' => [
            ['label' => $key, 'category_id' => $groceries, 'system_key' => $key, 'value' => wireMoney(300000), 'planned' => null],
            ['label' => $gym->id, 'category_id' => $gym->id, 'system_key' => null, 'value' => wireMoney(80000), 'planned' => null],
        ],
    ])->and($response->json('data.income.items.0.label'))->toBe($salary)
        ->and($response->json('data.wallet_id'))->toBe($this->wallet->id)
        ->and($response->json('data.range'))->toBe(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->and($response->getContent())->toContain('"meta":{}');
});

test('200 with nothing recorded is empty groups with zero totals', function () {
    $data = reportCall($this->credential, $this->uri)->assertValidResponse(200)->json('data');

    expect($data['expenses'])->toBe(['total' => wireMoney(0), 'planned' => null, 'items' => []])
        ->and($data['income']['items'])->toBe([]);
});

test('400, 404 and 422', function () {
    reportCall($this->credential, $this->uri.'?from=2026-09-01')->assertValidResponse(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    reportCall($this->credential, $this->uri.'?include_planned=1')->assertValidResponse(400);
    reportCall($this->credential, '/wallets/'.Str::uuid7().'/reports/by-category')->assertValidResponse(404);
    reportCall($this->credential, '/wallets/x/reports/by-category')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('401, 406, 426 and 405 with Allow', function () {
    assertReportSharedRefusals($this->credential, $this->uri);
});
