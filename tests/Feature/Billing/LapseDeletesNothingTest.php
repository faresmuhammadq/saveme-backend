<?php

use App\Modules\Billing\Application\Subscriptions\OperatorSubscriptions;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Losing access deletes, hides or degrades nothing (P-09 rule 6).
 */

test('expiry and revocation delete no row anywhere', function () {
    person();
    signInAs();
    $userId = User::query()->sole()->id;
    $expiring = Subscription::factory()->create(['user_id' => $userId, 'status' => 'grace', 'current_period_end' => now()->subDays(4), 'grace_until' => now()->subHour()]);
    $revoking = Subscription::factory()->forPlan('plus_yearly')->create(['user_id' => $userId]);
    $tables = ['users', 'user_keys', 'profiles', 'device_sessions', 'identity_links', 'subscriptions', 'plans'];
    $counts = fn (): array => collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();
    $identity = fn (): string => collect(['users', 'user_keys', 'profiles'])->map(fn (string $t): string => (string) DB::scalar("select coalesce(string_agg(row_to_json(x)::text, ',' order by 1), '') from {$t} x"))->implode("\n");
    $before = [$counts(), $identity()];

    $this->artisan('billing:lapse-subscriptions')->assertSuccessful();
    app(OperatorSubscriptions::class)->revoke((string) Str::uuid7(), $revoking->id, 'Chargeback');

    expect([$counts(), $identity()])->toBe($before)
        ->and($expiring->fresh()->status)->toBe('expired')
        ->and($revoking->fresh()->status)->toBe('revoked');
});
