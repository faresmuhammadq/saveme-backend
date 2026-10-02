<?php

use App\Modules\Sync\Application\ChangeFeedService;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Membership\MembershipService;
use App\Modules\Wallets\Application\Sync\WalletHead;
use App\Modules\Wallets\Application\Sync\WalletHeadsQuery;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * The wallet heads read (spec 017, US1; ADR-0021): which wallets, in what
 * order, and that every kind of write moves the head the phone compares with.
 */

/**
 * @return array<string, WalletHead> by wallet id
 */
function headsOf(string $userId, ?string $after = null, int $limit = 200): array
{
    $heads = [];

    foreach (app(WalletHeadsQuery::class)->page($userId, $after, $limit) as $head) {
        $heads[$head->walletId] = $head;
    }

    return $heads;
}

function headOf(string $userId, string $walletId): int
{
    return headsOf($userId)[$walletId]->head;
}

beforeEach(function () {
    Queue::fake();
    $this->ada = person('ada@example.test');
    $this->grace = person('grace@example.test');
    $this->wallet = ownedWallet($this->ada->id);
});

test('it lists exactly the caller\'s active memberships in live wallets, archived included, ordered by id', function () {
    $archived = ownedWallet($this->ada->id, ['archived_at' => now()]);
    $shared = ownedWallet($this->grace->id);
    addMember($shared->id, $this->ada->id, 'viewer');
    $left = ownedWallet($this->grace->id);
    addMember($left->id, $this->ada->id, 'editor');
    app(MembershipService::class)->leave($left->id, $this->ada->id);
    ownedWallet($this->ada->id, ['deleted_at' => now()]);
    ownedWallet($this->grace->id);

    $ids = array_keys(headsOf($this->ada->id));

    $expected = [$this->wallet->id, $archived->id, $shared->id];
    sort($expected);
    expect($ids)->toBe($expected);
});

test('head and purged equal the wallet columns', function () {
    recordTransaction($this->ada->id, $this->wallet->id);
    Wallet::query()->whereKey($this->wallet->id)->update(['purged_change_seq' => 2]);
    $row = DB::table('wallets')->where('id', $this->wallet->id)->first();

    $head = headsOf($this->ada->id)[$this->wallet->id];

    expect($head->head)->toBe((int) $row->change_seq)
        ->and($head->purged)->toBe(2);
});

test('it pages by wallet id with a one-row lookahead', function () {
    $others = [ownedWallet($this->ada->id), ownedWallet($this->ada->id), ownedWallet($this->ada->id)];
    $all = array_keys(headsOf($this->ada->id));

    $first = headsOf($this->ada->id, null, 3);
    $rest = headsOf($this->ada->id, array_keys($first)[1], 3);

    expect($all)->toHaveCount(count($others) + 1)
        ->and(array_keys($first))->toBe(array_slice($all, 0, 3))
        ->and(array_keys($rest))->toBe(array_slice($all, 2));
});

test('after a wallet feed is read to its end the stored position equals the head', function () {
    recordTransaction($this->ada->id, $this->wallet->id);
    recordTransaction($this->ada->id, $this->wallet->id);

    $cursor = null;
    do {
        $page = app(ChangeFeedService::class)->walletPage($this->ada->id, $this->wallet->id, $cursor, 1);
        $cursor = $page->syncCursor;
    } while ($page->hasMore);

    expect($page->syncPosition)->toBe(headOf($this->ada->id, $this->wallet->id));
});

test('every kind of write in the wallet moves its head above the stored position', function (string $kind) {
    $store = fn (): int => headOf($this->ada->id, $this->wallet->id);
    if ($kind === 'invitation') {
        [$owner, $credential, $wallet] = plusOwner('owner@example.test');
        $this->ada = $owner;
        $this->wallet = $wallet;
    }

    $before = $store();

    match ($kind) {
        'transaction' => recordTransaction($this->ada->id, $this->wallet->id),
        'membership' => app(MembershipService::class)->grant($this->wallet->id, $this->grace->id, Role::Viewer),
        'category reference' => DB::transaction(fn () => app(CategoryReferences::class)->publish($this->wallet->id, ownedCategory($this->ada->id)->id)),
        'recurring rule' => createRecurringRule($this->ada->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06'])),
        'invitation' => sendInvitation($credential, $this->wallet->id)->assertCreated(),
        'wallet edit' => walletCall('PUT', signInAs('ada@example.test')->json('data.credential'), '/wallets/'.$this->wallet->id, walletBody(['name' => 'Renamed'], 1))->assertSuccessful(),
    };

    expect($store())->toBeGreaterThan($before);
})->with(['transaction', 'membership', 'category reference', 'recurring rule', 'invitation', 'wallet edit']);

test('a transfer moves both wallets\' heads, and a recurring rule\'s occurrences are covered by the head', function () {
    $destination = ownedWallet($this->grace->id);
    addMember($destination->id, $this->ada->id, 'editor');
    $before = [headOf($this->ada->id, $this->wallet->id), headOf($this->ada->id, $destination->id)];

    recordTransaction($this->ada->id, $this->wallet->id, transferBody($destination->id));

    expect(headOf($this->ada->id, $this->wallet->id))->toBeGreaterThan($before[0])
        ->and(headOf($this->ada->id, $destination->id))->toBeGreaterThan($before[1]);

    freezeClock('2026-10-05 10:00:00');
    createRecurringRule($this->ada->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']));
    $highest = (int) DB::table('occurrences')->where('wallet_id', $this->wallet->id)->max('change_seq');

    expect($highest)->toBeGreaterThan(0)->and(headOf($this->ada->id, $this->wallet->id))->toBeGreaterThan($highest);
});
