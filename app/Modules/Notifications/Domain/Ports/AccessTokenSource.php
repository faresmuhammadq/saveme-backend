<?php

namespace App\Modules\Notifications\Domain\Ports;

interface AccessTokenSource
{
    /**
     * A current OAuth access token for the push service.
     */
    public function token(): string;

    /**
     * Drops the cached token, so the next call mints a new one.
     */
    public function forget(): void;
}
