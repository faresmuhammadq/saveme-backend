<?php

namespace App\Modules\Admin\Http\Navigation;

use LogicException;

final class UnknownNavigationGroup extends LogicException
{
    public static function key(string $group, string $entry): self
    {
        return new self("Navigation entry [{$entry}] names the undeclared group [{$group}].");
    }
}
