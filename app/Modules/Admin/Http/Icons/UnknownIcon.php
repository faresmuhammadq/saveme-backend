<?php

namespace App\Modules\Admin\Http\Icons;

use InvalidArgumentException;

class UnknownIcon extends InvalidArgumentException
{
    public static function named(string $name): self
    {
        return new self("No back-office icon is named [{$name}].");
    }
}
