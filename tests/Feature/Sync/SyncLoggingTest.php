<?php

use App\Modules\Sync\Application\TombstonePurgeRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * One summary line per feed read, batch and purge run; none carries a record
 * id, an amount, a note, a cursor or a body (spec 013, FR-027; P-15 rule 4).
 */

test('a feed read, a batch with every outcome and a purge run each log one summary line without content', function () {
    [$user, $credential] = walletCaller();
    $wallet = ownedWallet($user->id);
    $existing = recordTransaction($user->id, $wallet->id, transactionBody(['description' => 'SECRET-NOTE', 'amount_minor' => 123457]))->transaction;
    $categoryId = (string) Str::uuid7();

    $logged = [];
    Log::spy();
    Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
        $logged[] = [$message, $context];
    });

    $page = readFeed($credential, $wallet->id)->assertOk();
    $userPage = readFeed($credential, 'user')->assertOk();
    readFeed($credential, 'user', $userPage->json('meta.sync_cursor'))->assertOk();
    postBatch($credential, [
        syncItem('category', 'upsert', $categoryId, categoryBody()),
        syncItem('transaction', 'upsert', $existing->id, transactionBody(['description' => 'SECRET-EDIT', 'amount_minor' => 424243], 9), $wallet->id),
        syncItem('wallet', 'delete', (string) Str::uuid7(), ['version' => 1]),
    ])->assertOk();
    app(TombstonePurgeRun::class)->handle();

    $names = array_column($logged, 0);
    expect(array_count_values($names))->toMatchArray(['sync_feed_read' => 3, 'sync_batch' => 1, 'sync_tombstones_purged' => 1]);

    $text = json_encode($logged);
    foreach ([$user->id, $wallet->id, $existing->id, $categoryId, '123457', '424243', 'SECRET', $page->json('meta.sync_cursor'), $userPage->json('meta.sync_cursor')] as $secret) {
        expect($text)->not->toContain((string) $secret);
    }

    $batch = collect($logged)->firstWhere(0, 'sync_batch')[1];
    expect($batch)->toMatchArray(['items' => 3, 'applied' => 1, 'conflict' => 1, 'rejected' => 1])
        ->and(array_keys($batch['results'][0]))->toBe(['type', 'op', 'outcome', 'status', 'code']);
});
