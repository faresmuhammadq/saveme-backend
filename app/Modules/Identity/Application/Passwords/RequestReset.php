<?php

namespace App\Modules\Identity\Application\Passwords;

use App\Modules\Identity\Application\Jobs\SendResetMail;
use App\Modules\Identity\Domain\Ports\AddressLookup;

/**
 * A reset request does the same work whether or not the address is
 * registered: one queued job, decided off the request path (security
 * baseline, Uniform answers; spec 007, R-10).
 */
final class RequestReset
{
    public function __construct(private readonly AddressLookup $lookup) {}

    public function handle(string $email): void
    {
        SendResetMail::dispatch($this->lookup->for($email));
    }
}
