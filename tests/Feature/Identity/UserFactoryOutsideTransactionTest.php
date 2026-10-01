<?php

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The user factory works with no wrapping transaction (spec 010, R-13): the
 * deferred user_keys foreign key is only satisfied at commit, so the factory
 * opens its own. RefreshDatabase wraps every test, so the test steps out of
 * that transaction first and cleans up after itself.
 */

test('a user is created through the factory with no wrapping transaction', function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    $user = null;

    try {
        $user = User::factory()->verified()->create();

        expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
            ->and(DB::table('user_keys')->where('user_id', $user->id)->exists())->toBeTrue();
    } finally {
        if ($user !== null) {
            DB::table('user_keys')->where('user_id', $user->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }
});
