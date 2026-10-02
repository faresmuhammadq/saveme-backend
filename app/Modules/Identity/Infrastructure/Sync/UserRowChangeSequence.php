<?php

namespace App\Modules\Identity\Infrastructure\Sync;

use App\Modules\Identity\Domain\Ports\ChangeSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The user row is the scope counter (ADR-0008 rule 2). The UPDATE is the
 * increment and the row lock in one statement, so a value is never handed
 * out twice and the order of values is the order of commits.
 */
final class UserRowChangeSequence implements ChangeSequence
{
    public function next(string $userId): int
    {
        $row = DB::selectOne('UPDATE users SET change_seq = change_seq + 1 WHERE id = ? RETURNING change_seq', [$userId]);

        if ($row === null) {
            throw new RuntimeException('No user to count changes for.');
        }

        return (int) $row->change_seq;
    }
}
