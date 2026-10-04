<?php

namespace App\Modules\Admin\Infrastructure\Crypto;

use App\Modules\Admin\Domain\Ports\RecoveryCodeHasher;

/**
 * A fast keyed hash is right for recovery codes: each has 50 bits of entropy
 * and the key lives outside the database (spec 018, R-10, R-11).
 */
final class HmacRecoveryCodeHasher implements RecoveryCodeHasher
{
    public function hash(string $normalised): string
    {
        return hash_hmac('sha256', $normalised, HmacEmailLookup::key('code_key', 'ADMIN_CODE_KEY'));
    }
}
