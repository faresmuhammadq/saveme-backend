<?php

use App\Modules\Notifications\Application\DeviceService;
use App\Modules\Notifications\Domain\Registration\SessionNotCurrent;
use App\Modules\Notifications\Infrastructure\Models\PushRegistration;

test('one registration per session: registering twice leaves one row', function () {
    $user = person();
    $session = sessionIdFor($user);
    $devices = app(DeviceService::class);

    $devices->register($user->id, $session, $session, 'token-1');
    $devices->register($user->id, $session, $session, 'token-1');

    expect(PushRegistration::query()->count())->toBe(1);
});

test('a rotation replaces the sealed value and the lookup, and the old token is no longer anyone', function () {
    $user = person();
    $session = sessionIdFor($user);
    $devices = app(DeviceService::class);

    $devices->register($user->id, $session, $session, 'token-old');
    $before = PushRegistration::query()->firstOrFail();
    $devices->register($user->id, $session, $session, 'token-new');
    $after = PushRegistration::query()->firstOrFail();

    expect($after->token_sealed)->not->toBe($before->token_sealed)
        ->and($after->token_lookup)->not->toBe($before->token_lookup)
        ->and($after->id)->toBe($before->id)
        ->and($after->registered_at->equalTo($before->registered_at))->toBeTrue();
});

test('the same token again only refreshes the time and re-seals', function () {
    $user = person();
    $session = sessionIdFor($user);
    $devices = app(DeviceService::class);

    $first = $devices->register($user->id, $session, $session, 'token-1');
    $this->travel(3)->days();
    $second = $devices->register($user->id, $session, $session, 'token-1');

    expect($second->registeredAt)->toEqual($first->registeredAt)
        ->and($second->refreshedAt > $first->refreshedAt)->toBeTrue()
        ->and($second->tokenSealed)->not->toBe($first->tokenSealed);
});

test('a token moving to another session takes the registration with it, in the same unit of work', function () {
    $ada = person();
    $grace = person('grace@example.test');
    $adaSession = sessionIdFor($ada);
    $graceSession = sessionIdFor($grace);
    $devices = app(DeviceService::class);

    $devices->register($ada->id, $adaSession, $adaSession, 'shared-phone-token');
    $devices->register($grace->id, $graceSession, $graceSession, 'shared-phone-token');

    $rows = PushRegistration::query()->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->session_id)->toBe($graceSession)
        ->and($rows[0]->user_id)->toBe($grace->id);
});

test('a token moving between two sessions of one person leaves only the new one', function () {
    $user = person();
    $first = sessionIdFor($user);
    $second = sessionIdFor($user);
    $devices = app(DeviceService::class);

    $devices->register($user->id, $first, $first, 'token-1');
    $devices->register($user->id, $second, $second, 'token-1');

    expect(PushRegistration::query()->pluck('session_id')->all())->toBe([$second]);
});

test('an id that is not the current session is refused and stores nothing', function () {
    $user = person();
    $current = sessionIdFor($user);
    $other = sessionIdFor($user);

    expect(fn () => app(DeviceService::class)->register($user->id, $current, $other, 'token-1'))->toThrow(SessionNotCurrent::class)
        ->and(fn () => app(DeviceService::class)->unregister($user->id, $current, $other))->toThrow(SessionNotCurrent::class)
        ->and(PushRegistration::query()->count())->toBe(0);
});

test('unregistering removes only the current session and tolerates nothing to remove', function () {
    $user = person();
    $a = sessionIdFor($user);
    $b = sessionIdFor($user);
    $devices = app(DeviceService::class);
    $devices->register($user->id, $a, $a, 'token-a');
    $devices->register($user->id, $b, $b, 'token-b');

    $devices->unregister($user->id, $a, $a);
    $devices->unregister($user->id, $a, $a);

    expect(PushRegistration::query()->pluck('session_id')->all())->toBe([$b]);
});

test('an unverified account registers', function () {
    $user = person('unverified@example.test', verified: false);
    $session = sessionIdFor($user);

    app(DeviceService::class)->register($user->id, $session, $session, 'token-1');

    expect(PushRegistration::query()->count())->toBe(1);
});
