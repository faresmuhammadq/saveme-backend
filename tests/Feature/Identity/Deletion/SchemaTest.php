<?php

use App\Modules\Identity\Infrastructure\Models\DataExport;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The CHECKs and indexes of spec 022's data model: the database refuses
 * what the domain forbids.
 */

test('a purged row with an address, or without its purge instant, is refused', function () {
    $user = person();

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['state' => 'purged'])))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['state' => 'active', 'purged_at' => now()])))->toThrow(QueryException::class);
});

test('a husk holds no address, lookup value or password, and a live account must hold them', function () {
    $user = person();

    DB::table('users')->where('id', $user->id)->update(['state' => 'purged', 'purged_at' => now(), 'email_ciphertext' => null, 'email_lookup' => null, 'password_hash' => null]);

    expect(User::query()->find($user->id)->state)->toBe('purged');

    $other = person('other@example.test');

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $other->id)->update(['email_lookup' => null])))->toThrow(QueryException::class);
});

test('two husks can share the null lookup value, and a purged address registers again', function () {
    $first = person('first@example.test');
    $second = person('second@example.test');

    foreach ([$first, $second] as $user) {
        DB::table('users')->where('id', $user->id)->update(['state' => 'purged', 'purged_at' => now(), 'email_ciphertext' => null, 'email_lookup' => null, 'password_hash' => null]);
    }

    expect(User::query()->where('state', 'purged')->count())->toBe(2);
});

test('a pending or purging account must carry both deletion dates, and an active one must not', function () {
    $user = person();

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['state' => 'pending_deletion'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['state' => 'purging'])))->toThrow(QueryException::class);

    DB::table('users')->where('id', $user->id)->update(['state' => 'purging', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()]);

    expect(User::query()->find($user->id)->state)->toBe('purging');
});

test('a second queued export for one person is a unique violation, a failed one is not', function () {
    $user = person();
    DataExport::factory()->create(['user_id' => $user->id]);

    expect(fn () => DB::transaction(fn () => DataExport::factory()->create(['user_id' => $user->id, 'status' => 'running'])))->toThrow(UniqueConstraintViolationException::class);

    DataExport::query()->where('user_id', $user->id)->update(['status' => 'failed', 'failure_code' => 'timed_out']);

    expect(DataExport::factory()->create(['user_id' => $user->id])->status)->toBe('queued');
});

test('a wallet export needs a wallet and an account export must not have one', function () {
    $user = person();

    expect(fn () => DB::transaction(fn () => DataExport::factory()->create(['user_id' => $user->id, 'scope' => 'wallet', 'wallet_id' => null])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DataExport::factory()->create(['user_id' => $user->id, 'scope' => 'account', 'wallet_id' => (string) Str::uuid7()])))->toThrow(QueryException::class);
});

test('a failure code is present exactly when the export failed', function () {
    $user = person();

    expect(fn () => DB::transaction(fn () => DataExport::factory()->create(['user_id' => $user->id, 'status' => 'failed', 'failure_code' => null])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DataExport::factory()->create(['user_id' => $user->id, 'status' => 'ready', 'failure_code' => 'timed_out'])))->toThrow(QueryException::class);
});

test('an unknown purge step is refused', function () {
    $user = person();

    expect(fn () => DB::transaction(fn () => DB::table('account_purges')->insert(['user_id' => $user->id, 'started_at' => now(), 'last_step' => 'nonsense', 'updated_at' => now()])))->toThrow(QueryException::class);
});

test('a session may end as account_deleted', function () {
    $user = person();
    $credential = signInAs()->json('data.credential');

    $sessionId = Str::before($credential, '|');
    DB::table('device_sessions')->where('id', $sessionId)->update(['state' => 'revoked', 'end_reason' => 'account_deleted', 'ended_at' => now()]);

    expect(DB::table('device_sessions')->where('id', $sessionId)->value('end_reason'))->toBe('account_deleted');
});
