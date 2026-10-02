<?php

use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * SC-008 (spec 016): a wallet of 5 members, 20 pending invitations and 10,000
 * activity entries. Invitation create, accept and role change p95 <= 300 ms;
 * invitation list and an activity page of 50 p95 <= 300 ms. Slow to seed, so it
 * runs only on request, with Xdebug off:
 * `SHARING_PERF=1 XDEBUG_MODE=off dev exec -- vendor/bin/pest tests/Feature/Sharing/SharingPerformanceTest.php`.
 */

function sharingPercentile95(array $milliseconds): float
{
    sort($milliseconds);

    return $milliseconds[(int) ceil(0.95 * count($milliseconds)) - 1];
}

/**
 * @return list<float> milliseconds per call
 */
function sharingTimed(Closure $call, int $runs = 30): array
{
    $samples = [];

    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $call($i);
        $samples[] = (hrtime(true) - $start) / 1e6;
    }

    return $samples;
}

test('create, accept, role change, the invitation list and an activity page stay inside the targets', function () {
    config(['sharing.throttle.invites_per_day' => 100000, 'sharing.throttle.token_per_hour' => 100000]);
    [$owner, $ownerCredential, $wallet] = plusOwner();
    // The sizing (20 pending invitations) needs an unlimited member quota.
    $plan = Plan::factory()->create(['features' => ['sharing.enabled' => true, 'sharing.max_members_per_wallet' => null, 'export.enabled' => true, 'reports.advanced' => true, 'wallets.max' => null]]);
    Subscription::query()->where('user_id', $owner->id)->update(['plan_id' => $plan->id]);
    app()->forgetScopedInstances();
    $invitee = person('invitee@example.test');
    $inviteeCredential = signInAs('invitee@example.test')->json('data.credential');
    $member = person('member@example.test');
    addMember($wallet->id, $member->id, 'editor');

    // 10,000 activity entries over a month, newest last.
    $now = now();
    foreach (array_chunk(range(1, 10000), 1000) as $chunk) {
        DB::table('wallet_activity')->insert(array_map(fn (int $n): array => [
            'id' => (string) Str::uuid7(), 'wallet_id' => $wallet->id, 'occurred_at' => $now->copy()->subSeconds(10000 - $n)->format('Y-m-d H:i:s.uP'),
            'actor_user_id' => $owner->id, 'action' => 'created', 'subject_type' => 'transaction', 'subject_id' => (string) Str::uuid7(), 'role' => null,
        ], $chunk));
    }

    // 20 live pending invitations, links so no mail is involved.
    foreach (range(1, 20) as $_) {
        sendInvitation($ownerCredential, $wallet->id, ['invitee_email' => null])->assertCreated();
    }
    DB::statement('ANALYZE wallet_activity');
    DB::statement('ANALYZE invitations');
    DB::statement('ANALYZE wallet_members');

    $memberVersion = 1;
    $created = sharingTimed(fn () => sendInvitation($ownerCredential, $wallet->id, ['invitee_email' => null])->assertCreated());
    $accepted = [];
    foreach (range(1, 20) as $_) {
        $link = pendingInvitation($owner->id, $wallet->id, null);
        $start = hrtime(true);
        $response = respondToInvitation($inviteeCredential, 'accept', $link['token'])->assertOk();
        $accepted[] = (hrtime(true) - $start) / 1e6;
        walletCall('POST', $inviteeCredential, '/wallets/'.$wallet->id.'/leave', ['version' => $response->json('data.version')])->assertOk();
    }

    $roles = sharingTimed(function (int $i) use ($ownerCredential, $wallet, $member, &$memberVersion): void {
        $memberVersion = walletCall('PATCH', $ownerCredential, '/wallets/'.$wallet->id.'/members/'.$member->id, ['role' => $i % 2 === 0 ? 'viewer' : 'editor', 'version' => $memberVersion])->assertOk()->json('data.version');
    });

    $list = sharingTimed(fn () => walletCall('GET', $ownerCredential, '/wallets/'.$wallet->id.'/invitations?limit=50')->assertOk());
    $activity = sharingTimed(fn () => walletCall('GET', $ownerCredential, '/wallets/'.$wallet->id.'/activity?limit=50')->assertOk());

    $plan = implode("\n", array_map(fn ($row) => $row->{'QUERY PLAN'}, DB::select("EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM wallet_activity WHERE wallet_id = '{$wallet->id}' ORDER BY occurred_at DESC, id DESC LIMIT 51")));

    fwrite(STDOUT, sprintf(
        "\nSC-008 (ms, p95): invitation create %.1f, accept %.1f, role change %.1f, invitation list %.1f, activity page %.1f\n%s\n",
        sharingPercentile95($created), sharingPercentile95($accepted), sharingPercentile95($roles), sharingPercentile95($list), sharingPercentile95($activity), $plan,
    ));

    expect(sharingPercentile95($created))->toBeLessThan(300)
        ->and(sharingPercentile95($accepted))->toBeLessThan(300)
        ->and(sharingPercentile95($roles))->toBeLessThan(300)
        ->and(sharingPercentile95($list))->toBeLessThan(300)
        ->and(sharingPercentile95($activity))->toBeLessThan(300);
})->skip(fn () => getenv('SHARING_PERF') !== '1', 'Set SHARING_PERF=1 to measure SC-008.');
