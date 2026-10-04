<?php

namespace App\Modules\Admin\Domain\Ports;

interface RecoveryCodeStore
{
    /**
     * Deletes every earlier code of the operator and stores the new set.
     *
     * @param  list<string>  $hashes
     */
    public function replaceSet(string $operatorId, array $hashes): void;

    public function deleteAll(string $operatorId): void;

    /**
     * Marks the unused code with this hash as used. Call under the operator row lock.
     */
    public function consumeMatching(string $operatorId, string $hash): bool;

    public function unusedCount(string $operatorId): int;
}
