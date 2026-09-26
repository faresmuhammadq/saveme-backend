<?php

namespace App\Modules\Identity\Infrastructure\Sync;

use App\Modules\Identity\Domain\Ports\ChangeSequence;
use Illuminate\Support\Facades\DB;

/**
 * Draws change_seq from the shared PostgreSQL sequence. The Sync module (B14)
 * owns commit-order semantics and may replace this (spec 007, R-19).
 */
final class PostgresChangeSequence implements ChangeSequence
{
    public function next(): int
    {
        return (int) DB::scalar("select nextval('sync_change_seq')");
    }
}
