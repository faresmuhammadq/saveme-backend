<?php

namespace App\Modules\Identity\Domain\Address;

use Normalizer;

/**
 * The one normalisation every address lookup goes through (security baseline,
 * Finding a person by address): trim, Unicode NFKC, lowercase the whole
 * address. Nothing provider-specific: dots and plus-tags are kept, because
 * treating two addresses as one silently merges two people.
 */
final class AddressNormaliser
{
    public static function normalise(string $address): string
    {
        $normalised = Normalizer::normalize(trim($address), Normalizer::FORM_KC);

        return mb_strtolower($normalised === false ? trim($address) : $normalised);
    }
}
