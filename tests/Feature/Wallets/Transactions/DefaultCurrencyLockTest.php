<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Wallets\Application\TransactionQueryService;
use App\Modules\Wallets\Application\Transactions\TransactionService;

/*
 * The person's default currency locks while a live transaction recorded by
 * them exists (spec 012, US5 S3, FR-020, R-12; spec 008 FR-010).
 */

beforeEach(function () {
    $this->user = person();
    $this->credential = signInAs()->json('data.credential');
    $this->wallet = ownedWallet($this->user->id);
});

test('nobody is locked before a first transaction', function () {
    expect(app(TransactionQueryService::class)->hasLiveTransactionsRecordedBy($this->user->id))->toBeFalse();

    $this->withHeaders(asCredential($this->credential))->get('/api/v1/me')->assertJsonPath('data.profile.default_currency_locked', false);
});

test('a live transaction recorded by the person locks the default currency', function () {
    recordTransaction($this->user->id, $this->wallet->id);

    expect(app(TransactionQueryService::class)->hasLiveTransactionsRecordedBy($this->user->id))->toBeTrue();
    $this->withHeaders(asCredential($this->credential))->get('/api/v1/me')->assertJsonPath('data.profile.default_currency_locked', true);

    saveProfile($this->credential, profileBody(['default_currency' => 'USD']))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.pointer', '/default_currency')
        ->assertJsonPath('errors.0.code', 'IMMUTABLE_FIELD');
    expect(Profile::query()->where('user_id', $this->user->id)->sole()->default_currency)->toBe('EGP');
});

test('a transaction recorded by someone else does not lock it', function () {
    $partner = person('grace@example.test');
    addMember($this->wallet->id, $partner->id, 'editor');

    recordTransaction($partner->id, $this->wallet->id);

    expect(app(TransactionQueryService::class)->hasLiveTransactionsRecordedBy($this->user->id))->toBeFalse()
        ->and(app(TransactionQueryService::class)->hasLiveTransactionsRecordedBy($partner->id))->toBeTrue();
});

test('it unlocks when every transaction the person recorded is deleted', function () {
    $view = recordTransaction($this->user->id, $this->wallet->id);
    app(TransactionService::class)->delete($this->user->id, $this->wallet->id, $view->transaction->id, 1, presentTransaction());

    expect(app(TransactionQueryService::class)->hasLiveTransactionsRecordedBy($this->user->id))->toBeFalse();
    saveProfile($this->credential, profileBody(['default_currency' => 'USD']))->assertOk()->assertJsonPath('data.default_currency', 'USD');
});
