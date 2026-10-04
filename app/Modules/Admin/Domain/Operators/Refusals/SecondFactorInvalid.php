<?php

namespace App\Modules\Admin\Domain\Operators\Refusals;

use RuntimeException;

/**
 * A wrong, replayed or malformed one-time code or recovery code.
 */
final class SecondFactorInvalid extends RuntimeException {}
