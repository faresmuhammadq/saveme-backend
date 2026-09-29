<?php

use App\Modules\Identity\Application\Jobs\SendRegistrationMail;
use App\Modules\Identity\Application\Registration\RegisterAccount;
use App\Modules\Identity\Domain\Events\UserRegistered;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\EmailAlreadyRegistered;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserKey;
use App\Modules\Identity\Infrastructure\Repositories\EloquentUserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Registration creates user, key, profile and default subscription together,
 * or nothing (spec 007, FR-003).
 */

beforeEach(fn () => Queue::fake());

function registerOverApi(string $email, string $language = ''): void
{
    $headers = $language === '' ? jsonHeaders() : jsonHeaders(['Accept-Language' => $language]);
    test()->withHeaders($headers)->postJson('/api/v1/auth/register', ['email' => $email, 'password' => 'correct horse'])->assertStatus(202);
    test()->flushHeaders();
}

test('a new address creates one user, one data key and one profile', function () {
    registerOverApi('ada@example.test', 'ar');

    $user = User::query()->sole();
    expect(UserKey::query()->whereKey($user->id)->count())->toBe(1)
        ->and(Profile::query()->where('user_id', $user->id)->sole())
        ->locale->toBe('ar')
        ->timezone->toBe('Africa/Cairo')
        ->version->toBe(1);
});

test('the profile language falls back to English', function () {
    registerOverApi('ada@example.test');

    expect(Profile::query()->sole()->locale)->toBe('en');
});

test('the profile is part of the same unit of work', function () {
    $profiles = Mockery::mock(ProfileRepository::class);
    $profiles->shouldReceive('create')->once()->andThrow(new RuntimeException('profile insert failed'));
    app()->instance(ProfileRepository::class, $profiles);

    expect(fn () => app(RegisterAccount::class)->handle('ada@example.test', 'correct horse', null, null, 'en'))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0)
        ->and(Profile::query()->count())->toBe(0)
        ->and(UserKey::query()->count())->toBe(0);
});

test('registration creates no subscription: the free plan is the absence of one', function () {
    registerOverApi('ada@example.test');

    expect(DB::table('subscriptions')->count())->toBe(0);
});

test('an existing address is refused and left untouched', function () {
    $user = person('ada@example.test');
    $before = User::query()->find($user->id)->only(['password_hash', 'updated_at', 'email_ciphertext']);

    expect(fn () => app(RegisterAccount::class)->handle('ADA@example.test ', 'another password', null, null, 'en'))
        ->toThrow(EmailAlreadyRegistered::class);

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->find($user->id)->only(['password_hash', 'updated_at', 'email_ciphertext']))->toEqual($before);
    Queue::assertNothingPushed();
});

test('a new account is announced once, after commit, and gets one welcome mail job', function () {
    Event::fake([UserRegistered::class]);

    registerOverApi('ada@example.test');

    Event::assertDispatchedTimes(UserRegistered::class, 1);
    Queue::assertPushed(SendRegistrationMail::class, 1);
});

test('a registration that loses a race is refused like any existing address', function () {
    person('ada@example.test');
    $real = app(EloquentUserRepository::class);
    // The pre-check misses (the other request has not committed yet); the insert then hits the unique index.
    $users = Mockery::mock(UserRepository::class);
    $users->shouldReceive('findByLookup')->andReturn(null);
    $users->shouldReceive('create')->andReturnUsing(fn (string ...$args) => $real->create(...$args));
    app()->instance(UserRepository::class, $users);

    expect(fn () => app(RegisterAccount::class)->handle('ada@example.test', 'correct horse', null, null, 'en'))
        ->toThrow(EmailAlreadyRegistered::class);
    expect(User::query()->count())->toBe(1);
});
