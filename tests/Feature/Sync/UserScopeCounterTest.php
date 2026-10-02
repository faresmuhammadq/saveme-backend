<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The user scope counter is users.change_seq (spec 013, R-5; ADR-0008 rules
 * 2-5): every user-scoped write takes the next value, and the row holds the
 * highest.
 */

function userCounter(string $userId): int
{
    return (int) DB::table('users')->where('id', $userId)->value('change_seq');
}

test('a profile save, a sign-in, a session end and a category save each take the next value of the person\'s counter', function () {
    [$user, $credential] = walletCaller();
    $afterSignIn = userCounter($user->id);
    $session = DeviceSession::query()->where('user_id', $user->id)->firstOrFail();

    expect($session->change_seq)->toBeLessThanOrEqual($afterSignIn);

    saveProfile($credential, profileBody(['timezone' => 'Asia/Tokyo']))->assertOk();
    $profile = Profile::query()->where('user_id', $user->id)->firstOrFail();
    expect($profile->change_seq)->toBe($afterSignIn + 1)->and(userCounter($user->id))->toBe($afterSignIn + 1);

    signInAs('ada@example.test', 'correct horse', deviceBody())->assertOk();
    $newSession = DeviceSession::query()->where('user_id', $user->id)->where('id', '<>', $session->id)->firstOrFail();
    expect($newSession->change_seq)->toBe($afterSignIn + 2)->and(userCounter($user->id))->toBe($afterSignIn + 2);

    app('auth')->forgetGuards();
    $this->withHeaders(asCredential($credential))->deleteJson('/api/v1/auth/sessions/'.$newSession->id)->assertNoContent();
    $this->flushHeaders();
    expect($newSession->fresh()->change_seq)->toBe($afterSignIn + 3)->and(userCounter($user->id))->toBe($afterSignIn + 3);

    $categoryId = (string) Str::uuid7();
    app(CategoryService::class)->save($user->id, $categoryId, categoryValues(), 0, presentCategory());
    expect(Category::query()->findOrFail($categoryId)->change_seq)->toBe($afterSignIn + 4)->and(userCounter($user->id))->toBe($afterSignIn + 4);
});

test('two people\'s counters are independent', function () {
    $ada = User::factory()->create();
    $grace = User::factory()->create();

    app(CategoryService::class)->save($ada->id, (string) Str::uuid7(), categoryValues(), 0, presentCategory());
    app(CategoryService::class)->save($ada->id, (string) Str::uuid7(), categoryValues(['name' => 'Tea']), 0, presentCategory());
    app(CategoryService::class)->save($grace->id, (string) Str::uuid7(), categoryValues(), 0, presentCategory());

    expect(userCounter($ada->id))->toBe(2)->and(userCounter($grace->id))->toBe(1);
});

test('an identical replay moves nothing', function () {
    $user = User::factory()->create();
    $id = (string) Str::uuid7();
    app(CategoryService::class)->save($user->id, $id, categoryValues(), 0, presentCategory());
    $before = userCounter($user->id);

    app(CategoryService::class)->save($user->id, $id, categoryValues(), 1, presentCategory());

    expect(userCounter($user->id))->toBe($before);
});

test('a rolled-back write leaves the counter unchanged, so it has no holes', function () {
    $user = User::factory()->create();
    $before = userCounter($user->id);

    try {
        DB::transaction(function () use ($user): void {
            app(CategoryService::class)->save($user->id, (string) Str::uuid7(), categoryValues(), 0, presentCategory());

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(userCounter($user->id))->toBe($before);
});

test('a stale profile save does not move the counter', function () {
    [$user, $credential] = walletCaller();
    saveProfile($credential, profileBody(['timezone' => 'Asia/Tokyo']))->assertOk();
    $before = userCounter($user->id);

    saveProfile($credential, profileBody(['timezone' => 'Asia/Dubai'], 1))->assertStatus(409);

    expect(userCounter($user->id))->toBe($before);
});
