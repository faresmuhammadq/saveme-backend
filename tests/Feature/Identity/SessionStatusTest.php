<?php

use App\Modules\Identity\Application\Profiles\RecipientProfile;
use App\Modules\Identity\Application\Sessions\SessionStatus;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;

test('activeAmong returns only the person own sessions that are still active', function () {
    $ada = person();
    $other = person('grace@example.test');
    $active = DeviceSession::factory()->create(['user_id' => $ada->id]);
    $revoked = DeviceSession::factory()->revoked()->create(['user_id' => $ada->id]);
    $theirs = DeviceSession::factory()->create(['user_id' => $other->id]);

    $ids = app(SessionStatus::class)->activeAmong($ada->id, [$active->id, $revoked->id, $theirs->id, (string) Str::uuid7()]);

    expect($ids)->toBe([$active->id]);
});

test('activeAmong of nothing is nothing', function () {
    expect(app(SessionStatus::class)->activeAmong(person()->id, []))->toBe([]);
});

test('isCurrent is true only for the caller own active current session', function () {
    $ada = person();
    $current = DeviceSession::factory()->create(['user_id' => $ada->id]);
    $another = DeviceSession::factory()->create(['user_id' => $ada->id]);
    $ended = DeviceSession::factory()->revoked()->create(['user_id' => $ada->id]);
    $status = app(SessionStatus::class);

    expect($status->isCurrent($ada->id, $current->id, $current->id))->toBeTrue()
        ->and($status->isCurrent($ada->id, $another->id, $current->id))->toBeFalse()
        ->and($status->isCurrent($ada->id, $ended->id, $ended->id))->toBeFalse()
        ->and($status->isCurrent(person('grace@example.test')->id, $current->id, $current->id))->toBeFalse();
});

test('the recipient profile carries the language and timezone', function () {
    $ada = person();
    Profile::query()->where('user_id', $ada->id)->update(['locale' => 'ar', 'timezone' => 'Asia/Tokyo']);

    $recipient = app(RecipientProfile::class)->of($ada->id);

    expect($recipient->locale)->toBe('ar')->and($recipient->timezone)->toBe('Asia/Tokyo')->and($recipient->active)->toBeTrue();
});

test('a pending deletion is still active; purging, purged and unknown accounts are not', function () {
    $pending = User::factory()->pendingDeletion()->create();
    $purging = User::factory()->pendingDeletion()->create();
    User::query()->whereKey($purging->id)->update(['state' => 'purging']);
    $purged = User::factory()->pendingDeletion()->create();
    User::query()->whereKey($purged->id)->update(['state' => 'purged', 'purged_at' => now(), 'email_ciphertext' => null, 'email_lookup' => null, 'password_hash' => null, 'deletion_requested_at' => null, 'deletion_scheduled_for' => null]);
    $recipients = app(RecipientProfile::class);

    expect($recipients->of($pending->id)->active)->toBeTrue()
        ->and($recipients->of($purging->id)->active)->toBeFalse()
        ->and($recipients->of($purged->id)->active)->toBeFalse()
        ->and($recipients->of((string) Str::uuid7())->active)->toBeFalse();
});
