<?php

namespace App\Modules\Admin\Domain\Operators\Refusals;

use RuntimeException;

final class PendingSignInExpired extends RuntimeException {}
