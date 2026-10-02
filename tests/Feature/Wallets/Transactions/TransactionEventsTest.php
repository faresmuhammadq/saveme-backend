<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Events\TransactionPosted;
use App\Modules\Wallets\Domain\Events\TransactionVoided;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Events (spec 012, FR-024, R-13, US3 S8; P-11): after commit only, ids and
 * scalars only, none for a replay or a refused write.
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id);
    $this->other = ownedWallet($this->owner->id);
    $this->service = app(TransactionService::class);
});

test('a create dispatches TransactionPosted recorded', function () {
    Event::fake([TransactionPosted::class, TransactionVoided::class]);
    $view = recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['date' => '2026-09-30']));

    Event::assertDispatched(TransactionPosted::class, fn (TransactionPosted $event): bool => $event->change === 'recorded'
        && $event->transactionId === $view->transaction->id
        && $event->walletId === $this->wallet->id
        && $event->counterWalletId === null
        && $event->kind === 'expense'
        && $event->date === '2026-09-30'
        && $event->previousDate === null
        && $event->categoryId === $view->transaction->categoryId
        && $event->actorUserId === $this->owner->id);
});

test('an edit dispatches amended with the previous date and category', function () {
    Event::fake([TransactionPosted::class, TransactionVoided::class]);
    $view = recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['date' => '2026-09-30']));
    $newCategory = ownedCategory($this->owner->id);

    $this->service->patch($this->owner->id, $this->wallet->id, $view->transaction->id, ['date' => '2026-10-02', 'category_id' => $newCategory->id], 1, presentTransaction());

    Event::assertDispatched(TransactionPosted::class, fn (TransactionPosted $event): bool => $event->change === 'amended'
        && $event->date === '2026-10-02'
        && $event->previousDate === '2026-09-30'
        && $event->categoryId === $newCategory->id
        && $event->previousCategoryId === $view->transaction->categoryId);
});

test('a delete dispatches TransactionVoided and a restore dispatches restored', function () {
    Event::fake([TransactionPosted::class, TransactionVoided::class]);
    $view = recordTransaction($this->owner->id, $this->wallet->id, transferBody($this->other->id));

    $this->service->delete($this->owner->id, $this->wallet->id, $view->transaction->id, 1, presentTransaction());
    Event::assertDispatched(TransactionVoided::class, fn (TransactionVoided $event): bool => $event->transactionId === $view->transaction->id
        && $event->counterWalletId === $this->other->id
        && $event->kind === 'transfer'
        && $event->categoryId === null);

    $this->service->restore($this->owner->id, $this->wallet->id, $view->transaction->id, 2, presentTransaction());
    Event::assertDispatched(TransactionPosted::class, fn (TransactionPosted $event): bool => $event->change === 'restored');
});

test('a replay, an identical edit and a refused write dispatch nothing', function () {
    Event::fake([TransactionPosted::class, TransactionVoided::class]);
    $id = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->wallet->id, $id, transactionBody());
    Event::assertDispatchedTimes(TransactionPosted::class, 1);

    saveTransaction($this->owner->id, $this->wallet->id, $id, transactionBody());
    $this->service->patch($this->owner->id, $this->wallet->id, $id, ['amount_minor' => 2500], 1, presentTransaction());
    refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), transactionBody(['currency' => 'USD'])));
    refusalOf(fn () => $this->service->patch($this->owner->id, $this->wallet->id, $id, ['amount_minor' => 1], 9, presentTransaction()));

    Event::assertDispatchedTimes(TransactionPosted::class, 1);
    Event::assertNotDispatched(TransactionVoided::class);
});

test('the events carry identifiers and scalars only', function () {
    $properties = array_map(fn (ReflectionProperty $property): string => (string) $property->getType(), array_merge(
        (new ReflectionClass(TransactionPosted::class))->getProperties(),
        (new ReflectionClass(TransactionVoided::class))->getProperties(),
    ));

    foreach (array_unique($properties) as $type) {
        expect($type)->toBeIn(['string', '?string']);
    }
    expect(array_map(fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass(TransactionPosted::class))->getProperties()))
        ->not->toContain('amountMinor', 'description', 'memo', 'counterparty');
});

test('an event of a rolled-back unit of work is never heard', function () {
    $heard = [];
    Event::listen(TransactionPosted::class, function (TransactionPosted $event) use (&$heard): void {
        $heard[] = $event->change;
    });

    try {
        DB::transaction(function (): void {
            recordTransaction($this->owner->id, $this->wallet->id, transactionBody());

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($heard)->toBe([])->and(Transaction::query()->count())->toBe(0);
});
