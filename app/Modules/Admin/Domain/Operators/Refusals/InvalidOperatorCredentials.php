<?php

namespace App\Modules\Admin\Domain\Operators\Refusals;

use RuntimeException;

/**
 * Unknown email, wrong password, deactivated account or expired temporary
 * password: one refusal, so the answer reveals nothing (FR-013).
 */
final class InvalidOperatorCredentials extends RuntimeException {}
