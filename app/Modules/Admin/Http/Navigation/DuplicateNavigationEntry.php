<?php

namespace App\Modules\Admin\Http\Navigation;

use LogicException;

final class DuplicateNavigationEntry extends LogicException
{
    public static function key(string $key): self
    {
        return new self("A back-office navigation entry is already registered with the key [{$key}].");
    }
}
