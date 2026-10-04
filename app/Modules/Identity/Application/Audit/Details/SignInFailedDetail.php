<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * For a failure on an unregistered address there is no subject; the first
 * 8 hex characters of the lookup value group repeated attempts without being
 * reversible to an address.
 */
final readonly class SignInFailedDetail implements AuditDetail
{
    /**
     * @param  string|null  $stage  where a back-office failure happened (password, second_factor, enrolment, reconfirm); application sign-in leaves it out
     */
    public function __construct(private ?string $lookupPrefix = null, private ?string $stage = null) {}

    public static function forUnknownAddress(string $lookup, ?string $stage = null): self
    {
        return new self(substr($lookup, 0, 8), $stage);
    }

    public function toArray(): array
    {
        return array_filter(
            ['stage' => $this->stage, 'lookup_prefix' => $this->lookupPrefix],
            fn (?string $value): bool => $value !== null,
        );
    }
}
