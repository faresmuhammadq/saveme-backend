<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Transactions\TransactionListQuery;
use App\Modules\Wallets\Application\Transactions\TransactionService;

/*
 * Transaction authorization (spec 012, FR-009; matrix rows T-01, T-02 of
 * spec 010 section 6; P-08): owner and editor write, a viewer reads, a
 * non-member gets not-found. Every decision goes through WalletAuthorizer.
 */

beforeEach(function () {
    $this->owner = person();
    $this->editor = person('grace@example.test');
    $this->viewer = person('linus@example.test');
    $this->stranger = person('ken@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    addMember($this->wallet->id, $this->editor->id, 'editor');
    addMember($this->wallet->id, $this->viewer->id, 'viewer');
    $this->tid = recordTransaction($this->owner->id, $this->wallet->id)->transaction->id;
});

test('an owner and an editor may write', function () {
    expect(refusalOf(fn () => recordTransaction($this->owner->id, $this->wallet->id)))->toBeNull()
        ->and(refusalOf(fn () => recordTransaction($this->editor->id, $this->wallet->id)))->toBeNull();
});

test('a viewer is forbidden on every write', function () {
    $service = app(TransactionService::class);

    $refusals = [
        refusalOf(fn () => recordTransaction($this->viewer->id, $this->wallet->id)),
        refusalOf(fn () => $service->patch($this->viewer->id, $this->wallet->id, $this->tid, ['amount_minor' => 5], 1, presentTransaction())),
        refusalOf(fn () => $service->delete($this->viewer->id, $this->wallet->id, $this->tid, 1, presentTransaction())),
        refusalOf(fn () => $service->restore($this->viewer->id, $this->wallet->id, $this->tid, 1, presentTransaction())),
    ];

    foreach ($refusals as $refusal) {
        expect($refusal?->errorCode)->toBe(ErrorCode::Forbidden);
    }
});

test('a non-member gets not-found on every write and read, never forbidden', function () {
    $service = app(TransactionService::class);
    $reads = app(TransactionListQuery::class);

    $refusals = [
        refusalOf(fn () => recordTransaction($this->stranger->id, $this->wallet->id)),
        refusalOf(fn () => $service->patch($this->stranger->id, $this->wallet->id, $this->tid, ['amount_minor' => 5], 1, presentTransaction())),
        refusalOf(fn () => $service->delete($this->stranger->id, $this->wallet->id, $this->tid, 1, presentTransaction())),
        refusalOf(fn () => $reads->find($this->stranger->id, $this->wallet->id, $this->tid)),
        refusalOf(fn () => $reads->list($this->stranger->id, $this->wallet->id, [], null, 'date', true, null, 50)),
    ];

    foreach ($refusals as $refusal) {
        expect($refusal?->errorCode)->toBe(ErrorCode::NotFound);
    }
});

test('a member of only one wallet of a transfer may read it there but not edit, restore or delete it', function () {
    $source = ownedWallet($this->owner->id);
    $transfer = recordTransaction($this->owner->id, $source->id, transactionBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $this->wallet->id]))->transaction->id;
    $service = app(TransactionService::class);

    expect(app(TransactionListQuery::class)->find($this->editor->id, $this->wallet->id, $transfer)->transaction->id)->toBe($transfer)
        ->and(refusalOf(fn () => $service->patch($this->editor->id, $this->wallet->id, $transfer, ['memo' => 'mine'], 1, presentTransaction()))?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(refusalOf(fn () => saveTransaction($this->editor->id, $this->wallet->id, $transfer, transactionBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $this->wallet->id, 'memo' => 'mine'], 1)))?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(refusalOf(fn () => $service->delete($this->editor->id, $this->wallet->id, $transfer, 1, presentTransaction()))?->errorCode)->toBe(ErrorCode::Forbidden);

    $service->delete($this->owner->id, $source->id, $transfer, 1, presentTransaction());

    expect(refusalOf(fn () => $service->restore($this->editor->id, $this->wallet->id, $transfer, 2, presentTransaction()))?->errorCode)->toBe(ErrorCode::Forbidden);
});

test('every member reads', function () {
    $reads = app(TransactionListQuery::class);

    foreach ([$this->owner, $this->editor, $this->viewer] as $member) {
        expect($reads->find($member->id, $this->wallet->id, $this->tid)->transaction->id)->toBe($this->tid)
            ->and($reads->list($member->id, $this->wallet->id, [], null, 'date', true, null, 50)['items'])->toHaveCount(1);
    }
});

test('a transaction of another wallet is not found under this wallet', function () {
    $mine = ownedWallet($this->owner->id);
    $theirs = ownedWallet($this->stranger->id);
    $foreign = recordTransaction($this->stranger->id, $theirs->id)->transaction->id;

    expect(refusalOf(fn () => app(TransactionListQuery::class)->find($this->owner->id, $mine->id, $foreign))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(refusalOf(fn () => saveTransaction($this->owner->id, $mine->id, $foreign, transactionBody()))?->errorCode)->toBe(ErrorCode::NotFound);
});

test('the transaction code decides nothing by comparing a role', function () {
    $files = glob(dirname(__DIR__, 4).'/app/Modules/Wallets/Application/Transactions/*.php');

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('Role::');
    }
});
