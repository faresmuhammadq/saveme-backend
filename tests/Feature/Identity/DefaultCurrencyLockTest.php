<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Wallets\Application\TransactionQueryService;

/*
 * The default currency locks once the person has recorded a live transaction
 * (spec 008, FR-010; owner decision 2026-09-27). The query service is bound to
 * a fake here to isolate the profile rules; the ledger's own answer is tested
 * in tests/Feature/Wallets/Transactions/DefaultCurrencyLockTest.php (spec 012).
 */

function transactionsRecorded(bool $recorded): void
{
    app()->instance(TransactionQueryService::class, new class($recorded) implements TransactionQueryService
    {
        public function __construct(private readonly bool $recorded) {}

        public function hasLiveTransactionsRecordedBy(string $userId): bool
        {
            return $this->recorded;
        }

        public function authorAndCurrencyOf(string $transactionId): ?array
        {
            return null;
        }
    });
}

beforeEach(function () {
    $this->user = person();
    $this->credential = signInAs()->json('data.credential');
});

test('with a recorded transaction, changing the currency is IMMUTABLE_FIELD and nothing applies', function () {
    transactionsRecorded(true);

    $response = saveProfile($this->credential, profileBody(['default_currency' => 'USD', 'display_name' => 'Ada']));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('errors.0.pointer', '/default_currency')
        ->assertJsonPath('errors.0.code', 'IMMUTABLE_FIELD')
        ->assertJsonPath('errors.0.detail', __('problems.default_currency_locked'));
    expect(Profile::query()->where('user_id', $this->user->id)->sole())
        ->default_currency->toBe('EGP')
        ->display_name_ciphertext->toBeNull()
        ->version->toBe(1);
});

test('with a recorded transaction, repeating the current currency passes', function () {
    transactionsRecorded(true);

    saveProfile($this->credential, profileBody(['display_name' => 'Ada']))->assertOk();
});

test('the profile reports the lock', function () {
    transactionsRecorded(true);

    $this->withHeaders(asCredential($this->credential))->get('/api/v1/me')->assertJsonPath('data.profile.default_currency_locked', true);
});

test('a stale version is a conflict before it is a lock', function () {
    transactionsRecorded(true);

    saveProfile($this->credential, profileBody(['default_currency' => 'USD'], version: 7))->assertStatus(409);
});

test('without recorded transactions the currency changes', function () {
    transactionsRecorded(false);

    saveProfile($this->credential, profileBody(['default_currency' => 'USD']))->assertOk()->assertJsonPath('data.default_currency', 'USD');
});

test('with no recorded transaction in the ledger, nobody is locked', function () {
    $this->withHeaders(asCredential($this->credential))->get('/api/v1/me')->assertJsonPath('data.profile.default_currency_locked', false);
});
