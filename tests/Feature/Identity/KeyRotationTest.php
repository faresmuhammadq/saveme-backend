<?php

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Crypto\Keyring;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserKey;

/*
 * Rotating the key-encryption key re-wraps data keys only; every value stays
 * readable throughout (security baseline, Keys and rotation; spec 007, R-6).
 */

test('after adding a key and re-wrapping, every address still opens and names the new key', function () {
    $user = User::factory()->withEmail('ada@example.test')->create();

    config(['identity.keys' => [...config('identity.keys'), 'k2' => base64_encode(random_bytes(32))], 'identity.active_key' => 'k2']);
    app()->forgetInstance(Keyring::class);
    app()->forgetScopedInstances();

    $this->artisan('identity:rewrap-keys')->assertSuccessful();
    app()->forgetScopedInstances();

    expect(UserKey::query()->find($user->id)->kid)->toBe('k2')
        ->and(app(AttributeCipher::class)->open($user->id, $user->email_ciphertext))->toBe('ada@example.test');
});
