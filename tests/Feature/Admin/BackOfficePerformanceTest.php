<?php

use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * SC-005 (spec 021): the first render of each screen answers in under
 * 1.5 seconds at the 95th percentile with 10,000 accounts, 2,000 claims and
 * 100,000 audit entries, with Xdebug off. It runs only on request:
 * `RUN_PERF=1 XDEBUG_MODE=off dev exec -- vendor/bin/pest tests/Feature/Admin/BackOfficePerformanceTest.php`.
 */

function backOfficePercentile95(array $milliseconds): float
{
    sort($milliseconds);

    return $milliseconds[(int) ceil(0.95 * count($milliseconds)) - 1];
}

test('each screen renders inside the target at scale', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);

    $template = User::factory()->create();
    $users = [];
    foreach (range(1, 9999) as $n) {
        $users[] = [
            'id' => (string) Str::uuid7(), 'email_lookup' => hash('sha256', "perf{$n}@example.test"),
            'email_ciphertext' => $template->email_ciphertext, 'password_hash' => $template->password_hash,
            'state' => 'active', 'created_at' => now()->subDays($n % 90), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($users, 1000) as $chunk) {
        DB::table('users')->insert($chunk);
    }

    PaymentClaim::factory()->count(2000)->create();

    $entries = [];
    foreach (range(1, 100000) as $n) {
        $entries[] = [
            'id' => (string) Str::uuid7(), 'occurred_at' => now()->subMinutes($n), 'event' => 'session_issued', 'actor_type' => 'user',
            'actor_id' => $users[$n % 9999]['id'], 'subject_type' => 'session', 'subject_id' => (string) Str::uuid7(),
            'request_id' => null, 'origin' => '203.0.113.0/24', 'detail' => '{}',
        ];
    }
    foreach (array_chunk($entries, 2000) as $chunk) {
        DB::table('audit_entries')->insert($chunk);
    }

    $claim = PaymentClaim::query()->first();
    $screens = [
        'overview' => route('admin.overview'),
        'claims' => route('admin.claims'),
        'claim review' => route('admin.claims.show', ['claim' => $claim->id]),
        'accounts' => route('admin.accounts'),
        'account page' => route('admin.accounts.show', ['user' => $users[0]['id']]),
        'plans' => route('admin.plans'),
        'plan editor' => route('admin.plans.edit', ['plan' => Plan::query()->where('code', 'plus_monthly')->value('id')]),
        'audit' => route('admin.audit'),
    ];

    $report = [];
    foreach ($screens as $name => $url) {
        $this->get($url)->assertOk();
        $samples = [];
        foreach (range(1, 20) as $ignored) {
            $start = hrtime(true);
            $this->get($url)->assertOk();
            $samples[] = (hrtime(true) - $start) / 1e6;
        }
        $report[$name] = backOfficePercentile95($samples);
    }

    fwrite(STDOUT, "\nSC-005 (ms, p95): ".json_encode($report)."\n");

    foreach ($report as $name => $p95) {
        expect($p95)->toBeLessThan(1500, "{$name} p95 {$p95} ms");
    }
})->skip(fn () => getenv('RUN_PERF') !== '1', 'Set RUN_PERF=1 to measure SC-005.');
