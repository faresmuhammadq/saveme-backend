<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * The next change_seq for a syncable record (spec 007, R-19; B14 owns its
 * commit-order semantics).
 */
interface ChangeSequence
{
    public function next(): int;
}
