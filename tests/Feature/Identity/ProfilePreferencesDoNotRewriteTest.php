<?php

use Illuminate\Support\Facades\DB;

/*
 * Changing timezone, default currency or budget start day rewrites nothing
 * but the profile (P-12 rule 5; spec 008, FR-012).
 */

/**
 * @return array<string, string>
 */
function snapshotExceptProfiles(): array
{
    $tables = ['users', 'user_keys', 'device_sessions', 'identity_links', 'audit_entries', 'sign_in_failures', 'operator_notifications'];

    return collect($tables)->mapWithKeys(fn (string $table): array => [
        $table => (string) DB::scalar("select coalesce(string_agg(row_to_json(t)::text, chr(10) order by 1), '') from {$table} t"),
    ])->all();
}

test('saving new preferences changes only the profile row', function () {
    $user = person();
    $credential = signInAs()->json('data.credential');
    // A first authenticated call records last_used_at; snapshot after it.
    $this->withHeaders(asCredential($credential))->get('/api/v1/me')->assertOk();
    $this->flushHeaders();
    $before = snapshotExceptProfiles();
    $profileBefore = DB::table('profiles')->where('user_id', $user->id)->first();

    $this->freezeTime();
    saveProfile($credential, profileBody(['timezone' => 'Asia/Tokyo', 'default_currency' => 'JPY', 'budget_period_start_day' => 15]))->assertOk();

    $after = snapshotExceptProfiles();
    // The request itself refreshes the session's last-used time; nothing else may move.
    $after['device_sessions'] = preg_replace('/"last_used_at":"[^"]*"/', '', $after['device_sessions']);
    $before['device_sessions'] = preg_replace('/"last_used_at":"[^"]*"/', '', $before['device_sessions']);
    $after['device_sessions'] = preg_replace('/"updated_at":"[^"]*"/', '', $after['device_sessions']);
    $before['device_sessions'] = preg_replace('/"updated_at":"[^"]*"/', '', $before['device_sessions']);

    expect($after)->toBe($before)
        ->and(DB::table('profiles')->where('user_id', $user->id)->first())->not->toEqual($profileBefore);
});
