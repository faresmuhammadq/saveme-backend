<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The switch migration (spec 013, data-model "The switch migration"): after
 * it, no record of a person's scope is above users.change_seq, membership
 * stamps are unique per person, and a new write stamps above everything.
 */

test('the backfill leaves every stamp at or below the counter, memberships unique, and new writes above', function () {
    $ada = User::factory()->create();
    $grace = User::factory()->create();

    // Pre-switch state: stamps from the old shared sequence, counters at 0, memberships unstamped.
    Profile::query()->where('user_id', $ada->id)->delete();
    Profile::factory()->create(['user_id' => $ada->id, 'change_seq' => 500]);
    DeviceSession::factory()->create(['user_id' => $ada->id, 'change_seq' => 900]);
    ownedCategory($ada->id, ['change_seq' => 700]);
    $wallets = [ownedWallet($ada->id), ownedWallet($ada->id), ownedWallet($grace->id)];
    addMember($wallets[2]->id, $ada->id, 'viewer');

    DB::statement('ALTER TABLE wallet_members ALTER COLUMN member_change_seq DROP NOT NULL');
    DB::statement('UPDATE wallet_members SET member_change_seq = NULL');
    DB::statement('UPDATE users SET change_seq = 0');
    WalletMember::query()->where('wallet_id', $wallets[1]->id)->update(['deleted_at' => now()]);

    (require app_path('Modules/Sync/Infrastructure/Database/Migrations/2026_10_04_000003_switch_user_scope_counter.php'))->up();

    foreach ([$ada->id, $grace->id] as $userId) {
        $counter = (int) DB::table('users')->where('id', $userId)->value('change_seq');
        $stamps = [
            ...DB::table('profiles')->where('user_id', $userId)->pluck('change_seq')->all(),
            ...DB::table('device_sessions')->where('user_id', $userId)->pluck('change_seq')->all(),
            ...DB::table('categories')->where('user_id', $userId)->pluck('change_seq')->all(),
        ];
        $memberStamps = DB::table('wallet_members')->where('user_id', $userId)->pluck('member_change_seq')->all();

        expect($memberStamps)->each->not->toBeNull()
            ->and(array_unique($memberStamps))->toHaveCount(count($memberStamps))
            ->and(max([0, ...$stamps, ...$memberStamps]))->toBeLessThanOrEqual($counter);
    }

    // Ada's memberships (3, one tombstoned) are stamped above her highest earlier stamp (900).
    expect(DB::table('wallet_members')->where('user_id', $ada->id)->min('member_change_seq'))->toBeGreaterThan(900)
        ->and((int) DB::table('users')->where('id', $ada->id)->value('change_seq'))->toBe(903);

    $id = (string) Str::uuid7();
    app(CategoryService::class)->save($ada->id, $id, categoryValues(), 0, presentCategory());
    expect(Category::query()->findOrFail($id)->change_seq)->toBe(904);
});

test('NOT NULL is set on member_change_seq once the migration has run', function () {
    $nullable = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'wallet_members' AND column_name = 'member_change_seq'");

    expect($nullable->is_nullable)->toBe('NO');
});
