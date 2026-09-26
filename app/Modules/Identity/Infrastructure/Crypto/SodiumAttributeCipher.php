<?php

namespace App\Modules\Identity\Infrastructure\Crypto;

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\UserKey;
use RuntimeException;

/**
 * Envelope encryption (spec 007, R-6). Each person has a random data key,
 * stored wrapped by the active keyring key; their attributes are sealed with
 * the data key. Rotating the keyring re-wraps data keys only, and deleting a
 * person's data key makes all of their sealed values unreadable.
 *
 * Unwrapped data keys are cached for the life of this object only (one
 * request or job); they are never persisted.
 */
final class SodiumAttributeCipher implements AttributeCipher
{
    /** @var array<string, string> */
    private array $dataKeys = [];

    public function __construct(private readonly Keyring $keyring) {}

    public function newDataKeyFor(string $userId): void
    {
        $dataKey = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $kid = $this->keyring->activeKid();

        UserKey::query()->create([
            'user_id' => $userId,
            'wrapped_key' => Sealer::seal($dataKey, $this->keyring->key($kid), $kid, $userId),
            'kid' => $kid,
        ]);

        $this->dataKeys[$userId] = $dataKey;
    }

    public function seal(string $userId, string $plaintext): string
    {
        return Sealer::seal($plaintext, $this->dataKey($userId), 'dk', $userId);
    }

    public function open(string $userId, string $sealed): string
    {
        return Sealer::open($sealed, $this->dataKey($userId), $userId);
    }

    /**
     * Re-wraps a person's data key with the active keyring key.
     *
     * @return bool whether the row was on an older key
     */
    public function rewrap(string $userId): bool
    {
        $row = UserKey::query()->find($userId) ?? throw new RuntimeException('No data key for this person.');
        $active = $this->keyring->activeKid();

        if ($row->kid === $active) {
            return false;
        }

        $dataKey = $this->dataKey($userId);
        $row->forceFill([
            'wrapped_key' => Sealer::seal($dataKey, $this->keyring->key($active), $active, $userId),
            'kid' => $active,
        ])->save();

        return true;
    }

    public function forget(string $userId): void
    {
        unset($this->dataKeys[$userId]);
    }

    private function dataKey(string $userId): string
    {
        if (isset($this->dataKeys[$userId])) {
            return $this->dataKeys[$userId];
        }

        $row = UserKey::query()->find($userId) ?? throw new RuntimeException('No data key for this person: their attributes are unreadable.');

        return $this->dataKeys[$userId] = Sealer::open($row->wrapped_key, $this->keyring->key($row->kid), $userId);
    }
}
