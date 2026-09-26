<?php

namespace App\Modules\Identity\Domain\Address;

use InvalidArgumentException;

/**
 * An email address in its normalised form. Constructed only from a value
 * that is a syntactically valid address of at most 254 characters.
 */
final readonly class EmailAddress
{
    public string $normalised;

    public function __construct(string $address)
    {
        $normalised = AddressNormaliser::normalise($address);

        if (mb_strlen($normalised) > 254 || filter_var($normalised, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            throw new InvalidArgumentException('Not a valid email address.');
        }

        $this->normalised = $normalised;
    }
}
