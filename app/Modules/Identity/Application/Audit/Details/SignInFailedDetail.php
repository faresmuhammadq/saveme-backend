<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * For a failure on an unregistered address there is no subject; the first
 * 8 hex characters of the lookup value group repeated attempts without being
 * reversible to an address.
 */
final readonly class SignInFailedDetail implements AuditDetail
{
    public function __construct(private ?string $lookupPrefix = null) {}

    public static function forUnknownAddress(string $lookup): self
    {
        return new self(substr($lookup, 0, 8));
    }

    public function toArray(): array
    {
        return $this->lookupPrefix === null ? [] : ['lookup_prefix' => $this->lookupPrefix];
    }
}
