<?php

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Infrastructure\Crypto\Keyring;
use App\Modules\Identity\Infrastructure\Crypto\SodiumAttributeCipher;
use App\Modules\Identity\Infrastructure\Models\UserKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The second half of a key-encryption-key rotation (security baseline, Keys
 * and rotation): re-wraps every data key still on an older key. Attributes
 * are not re-encrypted; nothing is unreadable at any point.
 */
#[Signature('identity:rewrap-keys')]
#[Description('Re-wrap every data key that is not on the active keyring key')]
class RewrapKeys extends Command
{
    public function handle(Keyring $keyring): int
    {
        $active = $keyring->activeKid();
        $cipher = new SodiumAttributeCipher($keyring);
        $rewrapped = 0;

        UserKey::query()->where('kid', '!=', $active)->select('user_id')->chunkById(500, function ($keys) use ($cipher, &$rewrapped): void {
            foreach ($keys as $key) {
                $rewrapped += (int) $cipher->rewrap($key->user_id);
                $cipher->forget($key->user_id);
            }
        }, 'user_id');

        $this->components->info("{$rewrapped} data key(s) re-wrapped onto [{$active}].");

        foreach (UserKey::query()->selectRaw('kid, count(*) as total')->groupBy('kid')->pluck('total', 'kid') as $kid => $total) {
            $this->components->twoColumnDetail((string) $kid, (string) $total);
        }

        return self::SUCCESS;
    }
}
