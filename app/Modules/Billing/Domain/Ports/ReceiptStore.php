<?php

namespace App\Modules\Billing\Domain\Ports;

/**
 * Where sealed receipt bytes live (spec 020, R-5): a private disk, a path the
 * server chooses, never a client's name.
 */
interface ReceiptStore
{
    /**
     * @return string the path stored on the claim
     */
    public function put(string $claimId, string $sealedBytes): string;

    public function get(string $path): ?string;

    /**
     * Removes the file and its directory; a missing file is not an error.
     */
    public function delete(string $path): void;
}
