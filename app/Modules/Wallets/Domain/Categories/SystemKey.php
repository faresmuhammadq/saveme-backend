<?php

namespace App\Modules\Wallets\Domain\Categories;

use InvalidArgumentException;

/**
 * The stable, language-independent key of a platform category (ADR-0014):
 * lowercase, starts with a letter, 2 to 40 characters. Never renamed or
 * reused; reports group by it.
 */
final readonly class SystemKey
{
    public const PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a valid platform category key.");
        }

        $this->value = $value;
    }
}
