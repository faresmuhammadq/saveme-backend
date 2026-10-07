<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Infrastructure\Models\PlayAccountReference;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The reference is `hex(HMAC-SHA256(key[kid], "play-account:" . user_id))`
 * (spec 024, R-3); the value is stored, so it resolves back to the account
 * whatever key is active later.
 */
final class EloquentPlayAccountReferences implements PlayAccountReferences
{
    public function for(string $userId): string
    {
        $existing = PlayAccountReference::query()->whereKey($userId)->value('reference');

        if (is_string($existing)) {
            return $existing;
        }

        $kid = self::activeKid();

        DB::table('play_account_references')->insertOrIgnore([
            'user_id' => $userId,
            'reference' => hash_hmac('sha256', 'play-account:'.$userId, self::key($kid)),
            'key_id' => $kid,
            'created_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);

        return (string) PlayAccountReference::query()->whereKey($userId)->value('reference');
    }

    public function userFor(string $reference): ?string
    {
        $user = PlayAccountReference::query()->where('reference', $reference)->value('user_id');

        return is_string($user) ? $user : null;
    }

    public function delete(string $userId): int
    {
        return PlayAccountReference::query()->whereKey($userId)->delete();
    }

    private static function activeKid(): string
    {
        $kid = config('billing.google_play.account_reference_active_key');

        if (! is_string($kid) || ! isset(config('billing.google_play.account_reference_keys')[$kid])) {
            throw new RuntimeException('BILLING_PLAY_ACCOUNT_ACTIVE_KEY does not name a key in BILLING_PLAY_ACCOUNT_KEYS.');
        }

        return $kid;
    }

    private static function key(string $kid): string
    {
        $key = base64_decode((string) config("billing.google_play.account_reference_keys.{$kid}"), true);

        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException("Play account-reference key [{$kid}] is missing or shorter than 32 bytes.");
        }

        return $key;
    }
}
