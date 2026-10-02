<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Events\TransactionPosted;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionStateInvalid;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * TransactionService::recordForOccurrence (spec 014, R-6, T016): runs inside
 * the caller's unit of work, records as the rule's author, sets both links.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->wallet = $this->world['wallet'];
    $this->rule = createRecurringRule($this->world['owner']->id, $this->wallet->id)->rule;
    $this->occurrence = occurrencesOf($this->rule->id)->first();
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function occurrenceTransactionValues(object $occurrence, array $changes = []): array
{
    return [
        'kind' => $occurrence->kind, 'date' => $occurrence->date, 'amount_minor' => $occurrence->amount_minor, 'currency' => $occurrence->currency,
        'category_id' => $occurrence->category_id, 'counter_wallet_id' => $occurrence->counter_wallet_id, 'description' => $occurrence->description,
        'memo' => null, 'counterparty' => null, 'cleared' => false, ...$changes,
    ];
}

it('refuses to run outside a unit of work', function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        expect(fn () => app(TransactionService::class)->recordForOccurrence((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), occurrenceTransactionValues((object) ['kind' => 'expense', 'date' => '2026-11-01', 'amount_minor' => 1, 'currency' => 'EGP', 'category_id' => null, 'counter_wallet_id' => null, 'description' => null]), (string) Str::uuid7(), (string) Str::uuid7()))
            ->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});

it('records the transaction as the rule\'s author with both links, the balance moved and the event announced', function () {
    Event::fake([TransactionPosted::class]);
    $id = (string) Str::uuid7();

    $view = app(TransactionService::class)->recordForOccurrence($this->world['editor']->id, $this->world['owner']->id, $this->wallet->id, $id, occurrenceTransactionValues($this->occurrence), $this->rule->id, $this->occurrence->id);
    $transaction = Transaction::query()->findOrFail($id);

    expect($view->transaction->id)->toBe($id)
        ->and($transaction->created_by)->toBe($this->world['owner']->id)
        ->and($transaction->cleared)->toBeFalse()
        ->and($transaction->recurring_rule_id)->toBe($this->rule->id)
        ->and($transaction->occurrence_id)->toBe($this->occurrence->id)
        ->and(walletBalance($this->wallet->id))->toBe(-450000);
    Event::assertDispatched(TransactionPosted::class, fn (TransactionPosted $event): bool => $event->actorUserId === $this->world['editor']->id && $event->change === 'recorded');
    expectLedgerIntact();
});

it('refuses an actor who may not write, a viewer, as forbidden', function () {
    $refusal = refusalOf(fn () => app(TransactionService::class)->recordForOccurrence($this->world['viewer']->id, $this->world['owner']->id, $this->wallet->id, (string) Str::uuid7(), occurrenceTransactionValues($this->occurrence), $this->rule->id, $this->occurrence->id));

    expect($refusal?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(Transaction::query()->count())->toBe(0);
});

it('accepts a category of another member, ownership being settled when the rule was written', function () {
    $partnersCategory = ownedCategory($this->world['owner']->id, ['kind' => 'expense']);
    $id = (string) Str::uuid7();

    app(TransactionService::class)->recordForOccurrence($this->world['editor']->id, $this->world['owner']->id, $this->wallet->id, $id, occurrenceTransactionValues($this->occurrence, ['category_id' => $partnersCategory->id]), $this->rule->id, $this->occurrence->id);

    expect(Transaction::query()->findOrFail($id)->category_id)->toBe($partnersCategory->id);
});

it('still refuses a deleted category and a wrong currency', function () {
    $gone = ownedCategory($this->world['owner']->id, ['kind' => 'expense', 'deleted_at' => now()]);
    $service = app(TransactionService::class);

    $deleted = refusalOf(fn () => $service->recordForOccurrence($this->world['owner']->id, $this->world['owner']->id, $this->wallet->id, (string) Str::uuid7(), occurrenceTransactionValues($this->occurrence, ['category_id' => $gone->id]), $this->rule->id, $this->occurrence->id));
    $currency = refusalOf(fn () => $service->recordForOccurrence($this->world['owner']->id, $this->world['owner']->id, $this->wallet->id, (string) Str::uuid7(), occurrenceTransactionValues($this->occurrence, ['currency' => 'USD']), $this->rule->id, $this->occurrence->id));

    expect(fieldErrorsOf($deleted))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(fieldErrorsOf($currency))->toBe(['/currency CURRENCY_MISMATCH']);
});

it('links one transaction to an occurrence at most', function () {
    $service = app(TransactionService::class);
    $service->recordForOccurrence($this->world['owner']->id, $this->world['owner']->id, $this->wallet->id, (string) Str::uuid7(), occurrenceTransactionValues($this->occurrence), $this->rule->id, $this->occurrence->id);

    $second = refusalOf(fn () => $service->recordForOccurrence($this->world['owner']->id, $this->world['owner']->id, $this->wallet->id, (string) Str::uuid7(), occurrenceTransactionValues($this->occurrence), $this->rule->id, $this->occurrence->id));

    expect($second)->toBeInstanceOf(TransactionStateInvalid::class)
        ->and(Transaction::query()->count())->toBe(1);
});

it('refuses a transaction write carrying either link as an unknown field', function () {
    [$user, $credential] = walletCaller('writer@example.test');
    $wallet = ownedWallet($user->id);

    foreach (['recurring_rule_id', 'occurrence_id'] as $member) {
        transactionCall('PUT', $credential, $wallet->id, (string) Str::uuid7(), transactionBody([$member => (string) Str::uuid7()]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.pointer', '/'.$member)
            ->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');
    }
});

it('shows the links, null for a transaction recorded by hand, on a read', function () {
    [$user, $credential] = walletCaller('reader@example.test');
    $wallet = ownedWallet($user->id);
    $byHand = recordTransaction($user->id, $wallet->id)->transaction;

    transactionCall('GET', $credential, $wallet->id, $byHand->id)
        ->assertOk()
        ->assertJsonPath('data.recurring_rule_id', null)
        ->assertJsonPath('data.occurrence_id', null);
});
